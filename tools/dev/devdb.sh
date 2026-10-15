#!/usr/bin/env bash
#
# Project-local MariaDB for local development and verification.
#
# Deliberately runs on its OWN port (3316) with a datadir inside the workspace so it
# never touches a system MySQL/MariaDB on 3306 and needs no root privileges.
#
# Usage:
#   tools/dev/devdb.sh init     # create datadir + database + user (first run)
#   tools/dev/devdb.sh start    # start the server in the background
#   tools/dev/devdb.sh stop     # stop the server
#   tools/dev/devdb.sh status   # show server status
#   tools/dev/devdb.sh cli      # open a mysql shell as the app user
#   tools/dev/devdb.sh destroy  # stop and delete the datadir
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DATA_DIR="${DEVDB_DIR:-$REPO_ROOT/.local/mariadb}"
DB_PORT="${DEVDB_PORT:-3316}"
DB_SOCKET="$DATA_DIR/run/mysqld.sock"
DB_PIDFILE="$DATA_DIR/run/mysqld.pid"
DB_LOGFILE="$DATA_DIR/mysqld.log"

# Non-sensitive local-only development credentials. Never reuse these anywhere real.
DB_NAME="${DEVDB_NAME:-friendica}"
DB_USER="${DEVDB_USER:-friendica}"
DB_PASS="${DEVDB_PASS:-friendica}"

log() { printf '\033[36m[devdb]\033[0m %s\n' "$*"; }
die() { printf '\033[31m[devdb] %s\033[0m\n' "$*" >&2; exit 1; }

is_running() {
  [[ -f "$DB_PIDFILE" ]] && kill -0 "$(cat "$DB_PIDFILE")" 2>/dev/null
}

cmd_init() {
  command -v mariadb-install-db >/dev/null || die "mariadb-install-db not found"
  if [[ -f "$DATA_DIR/data/mysql/user.frm" || -d "$DATA_DIR/data/mysql" ]]; then
    log "datadir already initialised at $DATA_DIR/data (skipping)"
  else
    mkdir -p "$DATA_DIR/data" "$DATA_DIR/run"
    log "initialising datadir at $DATA_DIR/data"
    mariadb-install-db \
      --datadir="$DATA_DIR/data" \
      --auth-root-authentication-method=normal \
      --skip-test-db \
      >/dev/null
  fi

  mkdir -p "$DATA_DIR/run"
  is_running || cmd_start

  log "creating database '$DB_NAME' and user '$DB_USER'"
  mariadb --socket="$DB_SOCKET" -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
  log "ready: mysql://$DB_USER@127.0.0.1:$DB_PORT/$DB_NAME"
}

cmd_start() {
  is_running && { log "already running (pid $(cat "$DB_PIDFILE"))"; return 0; }
  [[ -d "$DATA_DIR/data" ]] || die "no datadir; run 'init' first"
  mkdir -p "$DATA_DIR/run"
  log "starting mariadbd on 127.0.0.1:$DB_PORT"
  # setsid detaches the server from this shell's process group, so it survives the shell
  # that started it (plain nohup does not: the child still dies with the session).
  setsid mariadbd \
    --datadir="$DATA_DIR/data" \
    --port="$DB_PORT" \
    --bind-address=127.0.0.1 \
    --socket="$DB_SOCKET" \
    --pid-file="$DB_PIDFILE" \
    --log-error="$DB_LOGFILE" \
    --skip-name-resolve \
    >/dev/null 2>&1 < /dev/null &
  for _ in $(seq 1 60); do
    if mariadb --socket="$DB_SOCKET" -u root -e 'SELECT 1' >/dev/null 2>&1; then
      log "started (pid $(cat "$DB_PIDFILE" 2>/dev/null || echo '?'))"
      return 0
    fi
    sleep 0.5
  done
  die "server did not become ready; see $DB_LOGFILE"
}

cmd_stop() {
  if is_running; then
    kill "$(cat "$DB_PIDFILE")" && log "stopped"
  else
    log "not running"
  fi
}

cmd_status() {
  if is_running; then
    log "running on 127.0.0.1:$DB_PORT (pid $(cat "$DB_PIDFILE"))"
    mariadb --socket="$DB_SOCKET" -u root -e "SELECT VERSION() AS version;"
  else
    log "not running"
  fi
}

cmd_cli() {
  mariadb --socket="$DB_SOCKET" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME"
}

cmd_destroy() {
  cmd_stop
  rm -rf "$DATA_DIR"
  log "datadir removed"
}

case "${1:-}" in
  init)    cmd_init ;;
  start)   cmd_start ;;
  stop)    cmd_stop ;;
  status)  cmd_status ;;
  cli)     cmd_cli ;;
  destroy) cmd_destroy ;;
  *)       die "usage: $0 {init|start|stop|status|cli|destroy}" ;;
esac
