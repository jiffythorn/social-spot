#!/usr/bin/env bash
#
# Thin installer for the Social Spot server (Linux and macOS).
#
#   bash packaging/install.sh [--force]
#
# It does NOT install the server itself - that is what the containers are for.
# It checks that Docker is present, asks a few questions, writes packaging/.env,
# and runs `docker compose up`. Re-running it against an existing .env just
# rebuilds and restarts (the upgrade path), unless --force rewrites the answers.
#
# Every answer is also overridable from the environment (e.g. ORG_NAME="Maple
# Club" bash packaging/install.sh), which is how this stays scriptable.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$DIR/.env"
FORCE=0

[[ "${1:-}" == "--force" ]] && FORCE=1

say()  { printf '\033[36m[install]\033[0m %s\n' "$*"; }
warn() { printf '\033[33m[install]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[31m[install]\033[0m %s\n' "$*" >&2; exit 1; }

command -v docker >/dev/null || die "Docker is not installed. Install Docker Desktop
  (https://www.docker.com/products/docker-desktop/) or your distro's docker
  package, then run this again."
docker compose version >/dev/null 2>&1 || die "Docker is here but the Compose plugin
  is not ('docker compose version' failed). Docker Desktop ships it; on Linux
  install the docker-compose-plugin package."

# An existing answers file means "start/upgrade", not "ask everything again".
if [[ -f "$ENV_FILE" && "$FORCE" -eq 0 ]]; then
  say "using the existing answers in packaging/.env (pass --force to redo them)"
  # shellcheck disable=SC1090
  source "$ENV_FILE"
else
  ask() { # ask <VAR> <prompt> <default> — keeps a preset environment value untouched
    local var="$1" prompt="$2" default="$3" current
    current="${!var:-}"
    [[ -n "$current" ]] && { printf '%s=%s\n' "$var" "$current"; return 0; }
    printf '%s [%s]: ' "$prompt" "$default" >&2
    read -r current || true
    printf '%s=%s\n' "$var" "${current:-$default}"
  }

  # Portable secret generation: openssl where present, /dev/urandom otherwise,
  # alphanumerics only so the value needs no quoting in .env or in SQL.
  rand_secret() {
    if command -v openssl >/dev/null; then
      openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 24
    else
      tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 24
    fi
    printf '\n'
  }

  say "a few questions (Enter accepts the default shown in brackets)"
  HTTP_PORT="${HTTP_PORT:-8080}"
  read -r -p "Port on this machine that serves the site [$HTTP_PORT]: " ans || true
  HTTP_PORT="${ans:-$HTTP_PORT}"

  BASE_URL="${BASE_URL:-http://localhost:$HTTP_PORT}"
  read -r -p "Public address members type into their browsers [$BASE_URL]: " ans || true
  BASE_URL="${ans:-$BASE_URL}"

  if [[ "$BASE_URL" == *"localhost"* && "$BASE_URL" != *":$HTTP_PORT"* && "$BASE_URL" != *":443"* && "$BASE_URL" != *":80"* ]]; then
    warn "the address port does not match the serve port ($HTTP_PORT); sign-in
    cookies will misbehave until they agree."
  fi

  eval "$(ask ORG_NAME 'Organization name' 'Social Spot')"

  ORG_PROFILE="${ORG_PROFILE:-hoa}"
  printf 'Kind of organization (this only sets the starting feature set;\n' >&2
  printf 'everything can still be toggled in the officer console afterwards):\n' >&2
  printf '  1) hoa        Homeowners association\n' >&2
  printf '  2) club       Club or society\n' >&2
  printf '  3) nonprofit  Non-profit organization\n' >&2
  printf '  4) community  Neighborhood or general community\n' >&2
  read -r -p "Choice [1]: " ans || true
  case "${ans:-1}" in
    1) ORG_PROFILE=hoa ;; 2) ORG_PROFILE=club ;;
    3) ORG_PROFILE=nonprofit ;; 4) ORG_PROFILE=community ;;
    *) warn "not a choice; keeping '$ORG_PROFILE'" ;;
  esac

  eval "$(ask ADMIN_NICK 'Administrator account name' 'admin')"
  eval "$(ask ADMIN_MAIL 'Administrator email' 'admin@example.com')"

  if [[ -z "${ADMIN_PASS:-}" ]]; then
    printf 'Administrator password (empty generates a safe one): ' >&2
    read -r -s ADMIN_PASS || true
    printf '\n' >&2
    [[ -z "$ADMIN_PASS" ]] && ADMIN_PASS="$(rand_secret)"
  fi

  DB_NAME="${DB_NAME:-socialspot}"
  DB_USER="${DB_USER:-socialspot}"
  DB_PASSWORD="$(rand_secret)"
  DB_ROOT_PASSWORD="$(rand_secret)"

  {
    printf '# Written by packaging/install.sh - passwords live here, never commit this file.\n'
    printf 'BASE_URL=%s\n' "$BASE_URL"
    printf 'HTTP_PORT=%s\n' "$HTTP_PORT"
    printf 'ORG_NAME=%s\n' "$ORG_NAME"
    printf 'ORG_PROFILE=%s\n' "$ORG_PROFILE"
    printf 'ADMIN_NICK=%s\n' "$ADMIN_NICK"
    printf 'ADMIN_PASS=%s\n' "$ADMIN_PASS"
    printf 'ADMIN_MAIL=%s\n' "$ADMIN_MAIL"
    printf 'DB_NAME=%s\n' "$DB_NAME"
    printf 'DB_USER=%s\n' "$DB_USER"
    printf 'DB_PASSWORD=%s\n' "$DB_PASSWORD"
    printf 'DB_ROOT_PASSWORD=%s\n' "$DB_ROOT_PASSWORD"
  } > "$ENV_FILE"
  chmod 600 "$ENV_FILE"
  say "answers written to packaging/.env"
fi

say "building and starting (first build fetches Friendica core and its
dependencies, so expect several minutes with network activity)"
(cd "$DIR" && docker compose up -d --build) || die "'docker compose up' failed;
the output above names the cause (often Docker not running)."

# Readiness of the APPLICATION is what matters: a bare 200 can be Apache serving
# an error page, while /login only renders once the database is connected.
say "waiting for the site to answer (up to 3 minutes on first boot)"
ready=0
for _ in $(seq 1 90); do
  if curl -fsS -o /dev/null "http://127.0.0.1:${HTTP_PORT:-8080}/login" 2>/dev/null; then
    ready=1
    break
  fi
  sleep 2
done

[[ "$ready" == "1" ]] || die "the site is not answering yet. It may still be on
  first-boot provisioning - follow it with:  docker compose -f packaging/compose.yaml logs -f app"

# shellcheck disable=SC1090
source "$ENV_FILE"
printf '\n\033[32mYour server is up at %s\033[0m\n' "$BASE_URL"
printf 'Sign in as \033[1m%s\033[0m with the administrator password' "$ADMIN_NICK"
[[ "$FORCE" -eq 1 ]] && printf ' shown when the answers were written'
printf ',\nthen open the Officer console. The generated database passwords are in\npackaging/.env - keep a copy somewhere safe.\n'
printf '\nUseful from here:  docker compose -f packaging/compose.yaml logs -f app\n'
printf 'Backup is one command - see docs/packaging.md.\n'
