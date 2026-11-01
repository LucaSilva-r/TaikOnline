#!/usr/bin/env bash
# Restores the database from the encrypted backups backup-db.sh makes. Put this beside compose.yaml.
#   ./restore-backup.sh list                     the backups there are
#   ./restore-backup.sh verify [snapshot]        checks a backup can be restored, changing nothing
#   ./restore-backup.sh download [snapshot]      saves its dump in backups/, changing nothing else
#   ./restore-backup.sh restore [snapshot]       replaces the database with it, after asking
# The snapshot is an ID from `list`, or the latest one by default.
set -euo pipefail
cd "$(dirname "$0")"

set -a
source <(grep -E '^BACKUP_' .env)
set +a

services=(app queue reverb scheduler)
action=${1:-}
snapshot=${2:-latest}
work=$(mktemp -d)
restarting=false

finish() {
    rm -rf "$work"
    if [ "$restarting" = true ]; then
        docker compose start "${services[@]}"
    fi
}
trap finish EXIT

restic() {
    RESTIC_REPOSITORY="$BACKUP_RESTIC_REPOSITORY" RESTIC_PASSWORD="$BACKUP_RESTIC_PASSWORD" \
        AWS_ACCESS_KEY_ID="$BACKUP_R2_ACCESS_KEY_ID" AWS_SECRET_ACCESS_KEY="$BACKUP_R2_SECRET_ACCESS_KEY" AWS_DEFAULT_REGION=auto \
        docker run --rm -e RESTIC_REPOSITORY -e RESTIC_PASSWORD -e AWS_ACCESS_KEY_ID -e AWS_SECRET_ACCESS_KEY -e AWS_DEFAULT_REGION \
        -v taikonline-restic-cache:/root/.cache/restic ${RESTIC_DOCKER_ARGS:-} restic/restic:0.18.0 "$@"
}

# Fetches the dump, refusing one cut short (pg_dump ends with this line).
fetch() {
    restic dump "$snapshot" /backup/taikonline.sql >"$1"
    if ! tail -n 5 "$1" | grep -q 'PostgreSQL database dump complete'; then
        echo "Snapshot $snapshot is not a complete dump." >&2
        exit 1
    fi
}

case "$action" in
    list)
        restic snapshots --host taikonline --tag db
        ;;
    verify)
        fetch "$work/dump.sql"
        echo "Snapshot $snapshot is complete: $(du -h "$work/dump.sql" | cut -f1)."
        echo "Rows in wdb_plays: $(awk '/^COPY public\.wdb_plays /{f=1;next} f&&/^\\\.$/{print n+0;exit} f{n++}' "$work/dump.sql")"
        restic check
        ;;
    download)
        mkdir -p backups
        umask 077
        target="backups/restored-$(date +%Y%m%d_%H%M%S).sql"
        fetch "$target"
        echo "Saved $target. It holds personal data: delete it when done."
        ;;
    restore)
        fetch "$work/dump.sql"
        echo "This replaces the whole database with snapshot $snapshot ($(du -h "$work/dump.sql" | cut -f1))."
        echo "The site goes down meanwhile, and everything since that backup is lost."
        read -r -p "Type restore to continue: " answer
        if [ "$answer" != restore ]; then
            echo "Cancelled."
            exit 0
        fi

        restarting=true
        docker compose stop "${services[@]}"

        mkdir -p backups
        umask 077
        safety="backups/before-restore-$(date +%Y%m%d_%H%M%S).sql"
        docker compose exec -T pgsql sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' </dev/null >"$safety"
        tail -n 5 "$safety" | grep -q 'PostgreSQL database dump complete'
        echo "The database as it was is in $safety."

        # One transaction: if anything fails, the database stays exactly as it was.
        { echo 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;'; cat "$work/dump.sql"; } |
            docker compose exec -T pgsql sh -c 'psql -q -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 --single-transaction' >/dev/null

        echo "Restored snapshot $snapshot."
        echo "Accounts deleted since that backup are back: delete them again, then delete $safety when you no longer need it."
        ;;
    *)
        sed -n '2,7p' "$0" | cut -c3-
        exit 1
        ;;
esac
