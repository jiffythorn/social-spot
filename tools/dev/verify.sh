#!/usr/bin/env bash
#
# The whole verification gate, in one command:
#
#   tools/dev/verify.sh
#
#   1. Friendica's own code is untouched (tools/dev/check-core-clean.sh)
#   2. a throwaway instance is provisioned from nothing
#   3. the end-to-end suite drives that instance through its real web UI
#
# Exits non-zero if either gate fails, which is what CI keys off. Both gates always run, so a
# single invocation tells you about both problems instead of only the first.
#
# The throwaway instance is deliberately NOT the developer's instance: it uses its own
# database, its own configuration directory and its own port, so running this cannot disturb
# whatever is already being worked on.
#
# Environment:
#   VERIFY_PORT      port for the throwaway instance (default 8099)
#   VERIFY_DB        database name (default friendica_ci)
#   VERIFY_KEEP      "1" to leave the instance and its database behind for inspection
#
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

PORT="${VERIFY_PORT:-8099}"
DB="${VERIFY_DB:-friendica_ci}"
CONFIG_DIR="$REPO_ROOT/.local/ci/config"
STATE_DIR="$REPO_ROOT/.local/ci"
BASE_URL="http://localhost:$PORT"

green() { printf '\033[32m%s\033[0m\n' "$*"; }
red()   { printf '\033[31m%s\033[0m\n' "$*"; }
head_() { printf '\n\033[36m=== %s\033[0m\n' "$*"; }

FAILED=0

# ---------------------------------------------------- 1. our sources reach the core tree
#
# On a fresh clone this is what puts our addon and theme where Friendica looks for them; in an
# existing development tree it is a no-op that re-asserts the same links. It runs before the
# cleanliness check so that check inspects the layout an instance would actually run with.
head_ "1/4  linking our sources into core"
if ! "$REPO_ROOT/tools/dev/link-into-core.sh"; then
  red "linking FAILED — core is not in a runnable state"
  exit 1
fi

# ------------------------------------------------------------------ 2. core stays untouched
head_ "2/4  core cleanliness"
if "$REPO_ROOT/tools/dev/check-core-clean.sh"; then
  true
else
  red "core-clean check FAILED"
  FAILED=1
fi

# ------------------------------------------------- 3. provision a throwaway instance
head_ "3/4  provisioning a throwaway instance ($DB on $BASE_URL)"
if ! "$REPO_ROOT/tools/dev/instance.sh" provision "$CONFIG_DIR" "$DB" "$BASE_URL"; then
  red "provisioning FAILED — cannot run the suite without an instance"
  exit 1
fi

# ------------------------------------------------------------------ 4. the end-to-end suite
head_ "4/4  end-to-end suite"

mkdir -p "$STATE_DIR"

stop_server() {
  SERVE_RUN_DIR="$STATE_DIR" \
  SERVE_PID_FILE="verify-$PORT.pid" \
    "$REPO_ROOT/tools/dev/serve.sh" stop >/dev/null 2>&1 || true
}
trap stop_server EXIT

# A stale server from an interrupted run would keep the port and serve a stale database, which
# is exactly the kind of thing that makes a green result meaningless.
stop_server

if ! SERVE_RUN_DIR="$STATE_DIR" \
     SERVE_PID_FILE="verify-$PORT.pid" \
     SERVE_LOG_FILE="verify-$PORT.log" \
     SERVE_CONFIG_DIR="$CONFIG_DIR" \
       "$REPO_ROOT/tools/dev/serve.sh" start "$PORT"; then
  red "the instance did not start; log follows"
  tail -30 "$STATE_DIR/verify-$PORT.log" 2>/dev/null || true
  exit 1
fi

# serve.sh already waits for an HTTP answer, but readiness of the *application* is what the
# suite needs: a page that renders a database error still answers 200, so ask for something
# that only works once the database is actually connected.
ready=0
for _ in $(seq 1 40); do
  if curl -fsS -o /dev/null "$BASE_URL/login" 2>/dev/null; then
    ready=1
    break
  fi
  sleep 0.5
done

if [[ "$ready" != "1" ]]; then
  red "the instance never served $BASE_URL/login"
  tail -30 "$STATE_DIR/verify-$PORT.log" 2>/dev/null || true
  exit 1
fi

BASE_URL="$BASE_URL" \
DEVDB_NAME="$DB" \
  python3 "$REPO_ROOT/tools/dev/e2e-orgsuite.py"
suite_status=$?

stop_server

if [[ "$suite_status" != "0" ]]; then
  red "the end-to-end suite FAILED (exit $suite_status)"
  FAILED=1
else
  green "the end-to-end suite passed"
fi

# --------------------------------------------------------------- result and clean-up
head_ "result"

if [[ "${VERIFY_KEEP:-0}" == "1" ]]; then
  printf 'kept the instance behind: database %s, config %s, port %s\n' "$DB" "$CONFIG_DIR" "$PORT"
else
  "$REPO_ROOT/tools/dev/instance.sh" destroy "$CONFIG_DIR" "$DB" >/dev/null 2>&1 \
    || red "warning: could not clean up the throwaway instance"
fi

if [[ "$FAILED" -eq 0 ]]; then
  green "verification passed"
else
  red "verification failed"
fi

exit "$FAILED"
