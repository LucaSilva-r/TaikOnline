#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR="$(dirname "$0")/backups"

# Load DB credentials from .env
set -a
source "$(dirname "$0")/.env"
set +a

# Find available backups
BACKUPS=($(ls -t "$BACKUP_DIR"/dump_*.sql 2>/dev/null || true))

if [ ${#BACKUPS[@]} -eq 0 ]; then
    echo "No backups found in $BACKUP_DIR"
    exit 1
fi

SELECTED_BACKUP=""

if [ $# -ge 1 ]; then
    # Use provided backup file
    if [ -f "$1" ]; then
        SELECTED_BACKUP="$1"
    elif [ -f "$BACKUP_DIR/$1" ]; then
        SELECTED_BACKUP="$BACKUP_DIR/$1"
    else
        echo "Error: Backup file '$1' not found."
        exit 1
    fi
else
    # Interactive selection
    echo "Available backups (most recent first):"
    for i in "${!BACKUPS[@]}"; do
        filename=$(basename "${BACKUPS[$i]}")
        echo "[$i] $filename"
    done

    echo -n "Select backup to restore [0]: "
    read -r SELECTION
    SELECTION=${SELECTION:-0}

    if ! [[ "$SELECTION" =~ ^[0-9]+$ ]] || [ "$SELECTION" -lt 0 ] || [ "$SELECTION" -ge "${#BACKUPS[@]}" ]; then
        echo "Invalid selection."
        exit 1
    fi

    SELECTED_BACKUP="${BACKUPS[$SELECTION]}"
fi

echo "Restoring from: $SELECTED_BACKUP..."

# Confirm before proceeding
echo -n "This will overwrite your current database. Are you sure? [y/N]: "
read -r CONFIRM
if [[ ! "$CONFIRM" =~ ^[yY](es)?$ ]]; then
    echo "Restore cancelled."
    exit 0
fi

# Recreate schema to ensure clean restore (wipe existing tables/views)
echo "Wiping database schema..."
docker exec -e PGPASSWORD="$DB_PASSWORD" taikonline-pgsql-1 \
    psql -U "$DB_USERNAME" -d "$DB_DATABASE" -c "DROP SCHEMA public CASCADE; CREATE SCHEMA public; ALTER SCHEMA public OWNER TO $DB_USERNAME;"

# Restore dump
echo "Restoring dump..."
docker exec -i -e PGPASSWORD="$DB_PASSWORD" taikonline-pgsql-1 \
    psql -U "$DB_USERNAME" -d "$DB_DATABASE" < "$SELECTED_BACKUP"

echo "Database successfully restored!"
