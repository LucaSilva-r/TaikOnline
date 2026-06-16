#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR="$(dirname "$0")/backups"
mkdir -p "$BACKUP_DIR"

TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
OUTPUT="$BACKUP_DIR/dump_${TIMESTAMP}.sql"

# Load DB credentials from .env
set -a
source "$(dirname "$0")/.env"
set +a

docker exec -e PGPASSWORD="$DB_PASSWORD" taikonline-pgsql-1 \
    pg_dump -U "$DB_USERNAME" "$DB_DATABASE" \
    > "$OUTPUT"

echo "Dump saved to: $OUTPUT"
