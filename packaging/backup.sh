#!/usr/bin/env bash
#
# One-step backup for the person who looks after the server.
#
#   bash packaging/backup.sh
#
# Writes a dated database dump into packaging/backups/. The database IS the
# organization's data (members, dues, documents, announcements); the rest
# regenerates from the installer answers, so this single file is the backup.
# Copy the newest file somewhere that is not this computer (a USB stick, a
# shared drive, cloud storage) - a backup on the same disk is barely a backup.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$DIR/.env"

[[ -f "$ENV_FILE" ]] || { echo "no $ENV_FILE - is the server installed?" >&2; exit 1; }

# shellcheck disable=SC1090
source "$ENV_FILE"

mkdir -p "$DIR/backups"
OUT="$DIR/backups/social-spot-backup-$(date +%F_%H%M).sql"

(cd "$DIR" && docker compose exec -T db mariadb-dump -u root -p"$DB_ROOT_PASSWORD" \
  --single-transaction "$DB_NAME" > "$OUT") || {
  echo "backup FAILED - is the server running?" >&2
  rm -f "$OUT"
  exit 1
}

printf 'backed up to %s (%s)\n' "$OUT" "$(du -h "$OUT" | cut -f1)"
printf 'now copy it somewhere that is not this computer.\n'
