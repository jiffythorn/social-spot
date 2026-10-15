#!/usr/bin/env bash
#
# Rehearses .github/workflows/verify.yml on this machine, without Docker and without act.
#
#   tools/dev/dryrun-ci.sh
#
# Why this exists rather than just running tools/dev/verify.sh: the interesting failures are the
# ones caused by state that happens to exist on a developer's machine and not on a runner. So this
# reproduces the two things that actually differ:
#
#   1. the file set. It copies ONLY what a fresh `git clone` would give you, so anything relying on
#      a file that is not in the repository simply is not there;
#   2. the database. It initialises a MariaDB datadir from nothing, on its own port, which is a
#      code path a developer's machine has usually never exercised because its datadir already
#      exists.
#
# What it CANNOT reproduce, and therefore leaves unverified: the runner image. PHP and its
# extensions are whatever is installed here, and the workflow's apt and setup-php steps are not
# executed. Those stay unverified until the job runs on GitHub.
#
# Environment:
#   DRYRUN_DIR    where to build the rehearsal (default .local/ci-dryrun)
#   DRYRUN_PORT   database port (default 3317, chosen to miss the usual development port 3316)
#   DRYRUN_HTTP   HTTP port for the rehearsal instance (default 8099)
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FRESH="${DRYRUN_DIR:-$REPO_ROOT/.local/ci-dryrun}"
DB_PORT="${DRYRUN_PORT:-3317}"
HTTP_PORT="${DRYRUN_HTTP:-8099}"

CORE_REPOSITORY="${CORE_REPOSITORY:-https://github.com/friendica/friendica.git}"
CORE_REF="${CORE_REF:-stable}"

green() { printf '\033[32m%s\033[0m\n' "$*"; }
red()   { printf '\033[31m%s\033[0m\n' "$*"; }
head_() { printf '\n\033[36m=== %s\033[0m\n' "$*"; }

cd "$REPO_ROOT" || exit 1

# Stops a database server left behind by an earlier rehearsal. This script owns its own server, so
# it must also be the one to shut it down; without this, the second run finds port $DB_PORT already
# held by the first run's server, MariaDB refuses to bind, and the rehearsal fails for a reason
# that has nothing to do with the workflow.
#
# Matched on the rehearsal's own datadir, so no other server on the machine can be affected. Note
# that this does NOT belong in verify.sh: on a developer's machine that script runs against the
# shared development database, which it must leave running.
stop_rehearsal_db() {
  local pids
  pids="$(pgrep -f "mariadbd .*--datadir=$FRESH/" 2>/dev/null || true)"
  [[ -z "$pids" ]] && return 0

  printf '  stopping a database server left by an earlier rehearsal (pid %s)\n' "$pids"
  # shellcheck disable=SC2086
  kill $pids 2>/dev/null || true

  for _ in $(seq 1 40); do
    pgrep -f "mariadbd .*--datadir=$FRESH/" >/dev/null 2>&1 || return 0
    sleep 0.25
  done

  # shellcheck disable=SC2086
  kill -9 $pids 2>/dev/null || true
}

# ------------------------------------------------------------------- 1. a fresh clone's file set
head_ "1/5  building a checkout containing only what a clone would give you"

stop_rehearsal_db
rm -rf "$FRESH"
mkdir -p "$FRESH"

# --cached covers anything committed; --others covers what is not yet. --exclude-standard applies
# .gitignore, which is what keeps friendica/ (fetched) and .local/ (runtime) out - exactly as they
# would be absent after a clone.
git ls-files --cached --others --exclude-standard > /tmp/dryrun-filelist.txt
count=0
while IFS= read -r f; do
  [[ -z "$f" ]] && continue
  mkdir -p "$FRESH/$(dirname "$f")"
  cp -p "$f" "$FRESH/$f"
  count=$((count + 1))
done < /tmp/dryrun-filelist.txt

printf '  copied %s files\n' "$count"
[[ -d "$FRESH/addon/orgsuite" ]] || { red "our addon is not in the checkout"; exit 1; }
[[ -e "$FRESH/friendica" ]] && { red "friendica/ leaked into the checkout; .gitignore is wrong"; exit 1; }
[[ -e "$FRESH/.local" ]] && { red ".local/ leaked into the checkout; .gitignore is wrong"; exit 1; }
green "  the checkout contains our sources and nothing else"

# -------------------------------------------------------- 2. fetch core the way the workflow does
head_ "2/5  fetching pristine Friendica core (ref: $CORE_REF)"

mkdir -p "$FRESH/friendica"
(
  cd "$FRESH/friendica" || exit 1
  git init -q
  git remote add origin "$CORE_REPOSITORY"
  git fetch -q --depth 1 origin "$CORE_REF" || exit 1
  git checkout -q -f FETCH_HEAD || exit 1
  echo "  core at $(git rev-parse --short HEAD)"
  test -f index.php || { echo "  core checkout looks wrong"; exit 1; }
) || { red "fetching core FAILED"; exit 1; }

green "  core fetched"

# ------------------------------------------------------------------- 3. link our sources in
# Before composer runs, because core's composer.json declares "classmap": ["addon/"], and the
# optimized autoloader fails with 'Could not scan for classes inside "addon/"' when that directory
# is absent - which it is in a pristine checkout, since upstream ignores /addon.
head_ "3/5  linking our sources into the core tree"
( cd "$FRESH" && bash tools/dev/link-into-core.sh ) || { red "linking FAILED"; exit 1; }

# ------------------------------------------------------------- 4. install core's dependencies
head_ "4/5  installing Friendica's dependencies"

# A pristine checkout has no vendor/ - upstream ignores it - so without this the console dies on
# "Failed opening required .../vendor/autoload.php".
#
# The workflow installs gmp, apcu and imagick so composer's platform requirements are satisfied.
# PHP extensions cannot be installed here without administrator rights, so the rehearsal ignores
# exactly the ones this machine lacks and names them. That difference is the one part of the
# dependency step this script cannot verify.
ignores=""
for ext in gmp apcu imagick; do
  if ! php -m | grep -qix "$ext"; then
    ignores="$ignores --ignore-platform-req=ext-$ext"
    printf '  note: this machine lacks ext-%s; the runner installs it (ignoring here)\n' "$ext"
  fi
done

if ! ( cd "$FRESH/friendica" && composer install -o --no-dev --no-interaction --no-progress $ignores >/tmp/dryrun-composer.log 2>&1 ); then
  red "composer install FAILED; see /tmp/dryrun-composer.log"
  tail -20 /tmp/dryrun-composer.log
  exit 1
fi

[[ -f "$FRESH/friendica/vendor/autoload.php" ]] || { red "vendor/autoload.php is still missing"; exit 1; }
green "  dependencies installed"

# ------------------------------------------------------------- 5. the gate, on its own database
head_ "5/5  running the gate against a database initialised from nothing"

# DEVDB_* points tools/dev/devdb.sh at a datadir inside the rehearsal, so it must run
# mariadb-install-db from scratch; DEV_DB_PORT is what instance.sh writes into the generated
# configuration, and VERIFY_PORT keeps the rehearsal off the developer's HTTP port.
cd "$FRESH" || exit 1

DEVDB_DIR="$FRESH/.local/mariadb" \
DEVDB_PORT="$DB_PORT" \
DEV_DB_PORT="$DB_PORT" \
VERIFY_PORT="$HTTP_PORT" \
VERIFY_DB=friendica_dryrun \
  bash tools/dev/verify.sh
status=$?

head_ "result"

# Leave nothing running: a server holding port $DB_PORT would break the next rehearsal (see above).
# The tree itself is kept, because inspecting it is the point of a failed rehearsal.
stop_rehearsal_db

if [[ "$status" -eq 0 ]]; then
  green "the workflow's steps succeed on a fresh checkout with a database built from nothing"
else
  red "the rehearsal FAILED (exit $status) — this is what the first CI run would have shown"
fi

printf '\nthe rehearsal tree is left at %s for inspection; its database server has been stopped.\n' "$FRESH"

exit "$status"
