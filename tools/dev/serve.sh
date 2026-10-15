#!/usr/bin/env bash
#
# Runs the local Friendica instance for development and verification.
#
#   tools/dev/serve.sh start [port]   # start in the background, logging to .local/serve.log
#   tools/dev/serve.sh stop           # stop the server this script started
#   tools/dev/serve.sh status         # is it up? does it answer HTTP?
#   tools/dev/serve.sh run [port]     # run in the foreground (Ctrl-C to stop)
#
# The server is deliberately bound to loopback only, and the database is the project-local
# MariaDB on port 3316 (start it with tools/dev/devdb.sh init). Nothing system-wide is touched.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
APP_ROOT="$REPO_ROOT/friendica"

# Resolves a possibly-relative path against the current directory without requiring it to
# exist. The state directory and the configuration directory are both handed to other processes
# that run from a different working directory, so a relative path would quietly point elsewhere.
abs() { case "$1" in /*) printf '%s' "$1" ;; *) printf '%s/%s' "$PWD" "$1" ;; esac; }

# State files are overridable so a second instance (a throwaway one for CI or for a
# verification run) can be started from this same checkout without clobbering the pid and log
# of the instance the developer is actually using. The defaults are the historical names, so
# an already-running server keeps responding to 'serve.sh stop'.
RUN_DIR="$(abs "${SERVE_RUN_DIR:-$REPO_ROOT/.local}")"
PID_FILE="$RUN_DIR/${SERVE_PID_FILE:-serve.pid}"
LOG_FILE="$RUN_DIR/${SERVE_LOG_FILE:-serve.log}"
PORT="${2:-8080}"
HOST="127.0.0.1"

# Optional: point this instance at its own configuration directory instead of
# friendica/config/. This is Friendica's supported mechanism for it, and it is what lets two
# instances with different databases and URLs share one checkout of the code.
if [[ -n "${SERVE_CONFIG_DIR:-}" ]]; then
  export FRIENDICA_CONFIG_DIR="$(abs "$SERVE_CONFIG_DIR")"
fi

log() { printf '\033[36m[serve]\033[0m %s\n' "$*"; }
die() { printf '\033[31m[serve]\033[0m %s\n' "$*" >&2; exit 1; }

# Needed on hosts whose system openssl.cnf is missing, otherwise Friendica cannot create keys.
export OPENSSL_CONF="${OPENSSL_CONF:-$REPO_ROOT/tools/dev/openssl.cnf}"

is_running() {
  [[ -f "$PID_FILE" ]] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null
}

cmd_start() {
  is_running && { log "already running (pid $(cat "$PID_FILE"))"; return 0; }

  [[ -d "$APP_ROOT" ]] || die "no $APP_ROOT"

  # The configuration lives in SERVE_CONFIG_DIR when one is set, so checking the default
  # location would reject a perfectly good instance and send the reader off to create a file
  # they do not need.
  local config_file="${SERVE_CONFIG_DIR:-$APP_ROOT/config}/local.config.php"
  [[ -f "$config_file" ]] || die "no $config_file; create one first (tools/dev/instance.sh provision)"

  mkdir -p "$RUN_DIR"

  log "starting PHP built-in server on http://$HOST:$PORT"

  # Friendica stores several paths as RELATIVE paths (smarty3.config_dir = 'view/smarty3'
  # among others) and resolves them against the process working directory. Apache and php-fpm
  # run with the working directory set to the docroot, so the built-in server has to be
  # started the same way or template compilation fails with "the folder view/smarty3 must be
  # writable by webserver".
  cd "$APP_ROOT"

  # The built-in server handles ONE request at a time by default, and a request that makes
  # the application call back into itself (a registration probe, a background worker tick, a
  # federation fetch) then deadlocks against its own server until the client times out.
  # Giving it a few workers removes that whole class of "why does this page hang" confusion.
  # This is a development convenience only; Apache and php-fpm already run concurrently.
  export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-8}"

  # The server writes its OWN pid, from inside the session it will run in, and only then
  # execs PHP. Recording the shell's $! instead looks equivalent and is not: setsid forks when
  # it is already a process-group leader, so $! is a short-lived parent and the real server is
  # its child. The pid file then points at a dead process, is_running() reports "not running"
  # while the port is still served, and stop cannot stop anything. That is how a stale server
  # survives into the next run and quietly answers with code nobody is editing any more.
  setsid sh -c '
    echo $$ > "$1"
    shift
    exec "$@"
  ' sh "$PID_FILE" php -S "$HOST:$PORT" -t "$APP_ROOT" "$REPO_ROOT/tools/dev/router.php" \
    >"$LOG_FILE" 2>&1 < /dev/null &

  for _ in $(seq 1 40); do
    if curl -fsS -o /dev/null "http://$HOST:$PORT/" 2>/dev/null; then
      if ! is_running; then
        log "the server answered but pid $(cat "$PID_FILE" 2>/dev/null) is not alive; see $LOG_FILE"
        return 1
      fi
      log "ready (pid $(cat "$PID_FILE")), log: $LOG_FILE"
      return 0
    fi
    sleep 0.25
  done

  log "did not answer yet; see $LOG_FILE"
  tail -20 "$LOG_FILE" || true
  return 1
}

cmd_stop() {
  if ! is_running; then
    log "not running"
    rm -f "$PID_FILE"
    return 0
  fi

  local pid
  pid="$(cat "$PID_FILE")"

  # Kill the whole process group, not just the master: PHP_CLI_SERVER_WORKERS forks a pool of
  # children, and a killed master leaves them holding the listening socket, so the port stays
  # bound and the next start fails or keeps serving the previous run. setsid made this the
  # group leader, so the negative pid addresses exactly our own processes and nothing else.
  kill -TERM -- "-$pid" 2>/dev/null || kill -TERM "$pid" 2>/dev/null || true

  for _ in $(seq 1 40); do
    kill -0 "$pid" 2>/dev/null || break
    sleep 0.25
  done

  if kill -0 "$pid" 2>/dev/null; then
    log "did not exit on TERM; sending KILL"
    kill -KILL -- "-$pid" 2>/dev/null || kill -KILL "$pid" 2>/dev/null || true
    sleep 0.5
  fi

  rm -f "$PID_FILE"

  if kill -0 "$pid" 2>/dev/null; then
    log "pid $pid is still alive; the port may stay bound"
    return 1
  fi

  log "stopped (pid $pid)"
}

cmd_status() {
  if is_running; then
    log "process running (pid $(cat "$PID_FILE"))"
  else
    log "process not running"
  fi

  code="$(curl -s -o /dev/null -w '%{http_code}' "http://$HOST:$PORT/" || echo '000')"
  log "HTTP GET http://$HOST:$PORT/ -> $code"
}

cmd_run() {
  cd "$APP_ROOT"
  exec php -S "$HOST:$PORT" -t "$APP_ROOT" "$REPO_ROOT/tools/dev/router.php"
}

case "${1:-}" in
  start)  cmd_start ;;
  stop)   cmd_stop ;;
  status) cmd_status ;;
  run)    cmd_run ;;
  *)      die "usage: $0 {start|stop|status|run} [port]" ;;
esac
