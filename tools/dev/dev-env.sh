#!/usr/bin/env bash
#
# Loads the local development environment for this fork.
#
#   source tools/dev/dev-env.sh
#
# Exports:
#   OPENSSL_CONF  a working OpenSSL config, needed on hosts whose system config is a broken
#                 symlink (otherwise openssl_pkey_new() fails and Friendica cannot create
#                 node or user keys).
#   FRIENDICA_URL base URL of the local instance.
#   DEV_DB_*      the project-local database coordinates.
#
# Safe to source repeatedly.

ORGSUITE_REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
export ORGSUITE_REPO_ROOT

export OPENSSL_CONF="$ORGSUITE_REPO_ROOT/tools/dev/openssl.cnf"

export FRIENDICA_URL="${FRIENDICA_URL:-http://localhost:8080}"

export DEV_DB_HOST="${DEV_DB_HOST:-127.0.0.1}"
export DEV_DB_PORT="${DEV_DB_PORT:-3316}"
export DEV_DB_NAME="${DEV_DB_NAME:-friendica}"
export DEV_DB_USER="${DEV_DB_USER:-friendica}"
export DEV_DB_PASS="${DEV_DB_PASS:-friendica}"

printf '\033[36m[dev-env]\033[0m OPENSSL_CONF=%s\n' "$OPENSSL_CONF"
printf '\033[36m[dev-env]\033[0m FRIENDICA_URL=%s\n' "$FRIENDICA_URL"
printf '\033[36m[dev-env]\033[0m database=%s@%s:%s/%s\n' "$DEV_DB_USER" "$DEV_DB_HOST" "$DEV_DB_PORT" "$DEV_DB_NAME"
