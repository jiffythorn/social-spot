#!/usr/bin/env bash
#
# Validates everything under packaging/ that can be validated without Docker.
#
#   bash packaging/check.sh
#
# A green result means the files are syntactically sound and reference each
# other consistently. It does NOT mean the image builds or boots - that needs a
# Docker daemon, which this checkout may not have. Build and boot the stack on a
# machine with Docker before calling any release ready (see docs/packaging.md).
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FAILED=0

pass() { printf '  \033[32mPASS\033[0m %s\n' "$*"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$*"; FAILED=1; }

printf '\033[36m== shell syntax\033[0m\n'
for script in install.sh docker-entrypoint.sh check.sh make-dist.sh backup.sh; do
  if bash -n "$DIR/$script"; then
    pass "$script parses"
  else
    fail "$script has a syntax error"
  fi
done

printf '\033[36m== compose file\033[0m\n'
if python3 - "$DIR/compose.yaml" <<'EOF'; then
import sys, yaml
with open(sys.argv[1]) as fh:
    doc = yaml.safe_load(fh)
services = doc.get('services', {})
assert {'app', 'db', 'worker'} <= set(services), 'expected app, db and worker services'
for name in ('app', 'worker'):
    build = services[name].get('build', {})
    assert build.get('dockerfile') == 'packaging/Dockerfile', f'{name} must build packaging/Dockerfile'
    assert build.get('context') == '..', f'{name} must build from the repository root'
assert 'healthcheck' in services['db'], 'db needs a healthcheck (app and worker wait on it)'
assert services['app']['depends_on']['db']['condition'] == 'service_healthy', 'app must wait for a healthy db'
print('compose.yaml: services, build contexts and health gating OK')
EOF
  pass "compose.yaml parses with the expected shape"
else
  fail "compose.yaml is invalid or misshapen"
fi

printf '\033[36m== cross-references\033[0m\n'
# Every ${VAR} the stack or entrypoint requires must be documented in .env.example,
# or an installer author silently drops a variable the container needs at boot.
# Uppercase only: that is the convention for environment, and it keeps shell
# locals such as ${tables:-0} out of the list.
required_vars="$(
  grep -hoE '\$\{[A-Z_][A-Z0-9_]*' "$DIR/compose.yaml" "$DIR/docker-entrypoint.sh" \
  | sed 's/^\${//' | sort -u)"
missing=0
while IFS= read -r var; do
  [[ -z "$var" ]] && continue
  # Values the stack provides to itself rather than asking the installer for:
  # the MARIADB_* set is mapped from DB_* by compose.yaml, and DB_HOST/DB_PORT
  # are the compose service wiring (the container always reaches `db:3306`).
  case "$var" in
    MARIADB_ROOT_PASSWORD|MARIADB_DATABASE|MARIADB_USER|MARIADB_PASSWORD|DB_HOST|DB_PORT) continue ;;
  esac
  if grep -qE "^#? ?$var=" "$DIR/.env.example"; then
    :
  else
    fail "\$$var is required but not documented in .env.example"
    missing=1
  fi
done <<< "$required_vars"
[[ "$missing" -eq 0 ]] && pass "every required variable is documented in .env.example"

# The Dockerfile must actually provide what the entrypoint assumes.
grep -q 'gosu' "$DIR/Dockerfile" \
  && pass "gosu is installed (the entrypoint drops privileges with it)" \
  || fail "entrypoint uses gosu but the Dockerfile does not install it"
[[ -f "$DIR/assets/htaccess" ]] \
  && pass "the Apache rewrite source exists" \
  || fail "Dockerfile copies packaging/assets/htaccess which is missing"
grep -qE '^FROM php:8\.3' "$DIR/Dockerfile" \
  && pass "PHP is pinned to 8.3 (the version the suite is verified on)" \
  || fail "PHP base image is not pinned to 8.3"
grep -qE 'image: mariadb:[0-9]' "$DIR/compose.yaml" \
  && pass "MariaDB image is version-pinned" \
  || fail "MariaDB image is unpinned"

# The secret must stay out of git.
if git check-ignore -q "$DIR/.env" 2>/dev/null; then
  pass "packaging/.env is gitignored"
else
  fail "packaging/.env is NOT gitignored - passwords would be committable"
fi

printf '\033[36m== installers\033[0m\n'
grep -q 'compose.yaml' "$DIR/install.sh" && grep -q 'compose.yaml' "$DIR/install.ps1" \
  && pass "both installers drive compose.yaml" \
  || fail "an installer does not reference compose.yaml"
grep -q '\.env' "$DIR/install.sh" && grep -q '\.env' "$DIR/install.ps1" \
  && pass "both installers write .env" \
  || fail "an installer does not write .env"
# The double-click wrappers must point at the real scripts, or a click fails
# with a message nobody can act on.
grep -q 'install\.ps1' "$DIR/Install-Windows.bat" \
  && pass "Install-Windows.bat launches install.ps1" \
  || fail "Install-Windows.bat does not launch install.ps1"
grep -q 'mariadb-dump' "$DIR/Backup-Windows.bat" \
  && pass "Backup-Windows.bat performs the dump" \
  || fail "Backup-Windows.bat does not perform the dump"
[[ -f "$DIR/../docs/office-install-guide.md" ]] \
  && pass "the office install guide exists" \
  || fail "docs/office-install-guide.md is missing"
grep -q 'Install-Windows' "$DIR/../docs/office-install-guide.md" \
  && pass "the guide names the file staff actually click" \
  || fail "the guide does not name Install-Windows"
for var in BASE_URL HTTP_PORT ORG_NAME ORG_PROFILE ADMIN_NICK ADMIN_PASS ADMIN_MAIL DB_NAME DB_USER DB_PASSWORD DB_ROOT_PASSWORD; do
  grep -q "$var" "$DIR/install.sh" || { fail "install.sh never sets $var"; break; }
  grep -q "$var" "$DIR/install.ps1" || { fail "install.ps1 never sets $var"; break; }
done
pass "both installers cover all eleven .env variables"

if [[ "$FAILED" -eq 0 ]]; then
  printf '\033[32mpackaging checks passed (syntax and consistency only - see the header)\033[0m\n'
else
  printf '\033[31mpackaging checks failed\033[0m\n'
fi
exit "$FAILED"
