#!/usr/bin/env bash
# Daily encrypted database backup to an S3-compatible bucket, kept 30 days. restic runs from its
# image, so the host needs only Docker. Put this beside compose.yaml, fill in the BACKUP_ values in .env
# (see .env.example), run `./backup-db.sh init` once, then run it from cron:
#   15 4 * * * /home/debian/TaikOnline2/backup-db.sh >> /home/debian/TaikOnline2/backups/backup.log 2>&1
# Keep BACKUP_RESTIC_PASSWORD somewhere else too: without it the backups cannot be read.
set -euo pipefail
cd "$(dirname "$0")"

# Only these lines: the rest of .env is the app's business.
set -a
source <(grep -E '^BACKUP_' .env)
set +a

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

restic() {
    RESTIC_REPOSITORY="$BACKUP_RESTIC_REPOSITORY" RESTIC_PASSWORD="$BACKUP_RESTIC_PASSWORD" \
        AWS_ACCESS_KEY_ID="$BACKUP_R2_ACCESS_KEY_ID" AWS_SECRET_ACCESS_KEY="$BACKUP_R2_SECRET_ACCESS_KEY" AWS_DEFAULT_REGION=auto \
        docker run --rm -e RESTIC_REPOSITORY -e RESTIC_PASSWORD -e AWS_ACCESS_KEY_ID -e AWS_SECRET_ACCESS_KEY -e AWS_DEFAULT_REGION -v "$work:/backup:ro" -v taikonline-restic-cache:/root/.cache/restic \
        ${RESTIC_DOCKER_ARGS:-} restic/restic:0.18.0 "$@"
}

if [ "${1:-}" = init ]; then
    restic init
    exit
fi

docker compose exec -T pgsql sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' </dev/null >"$work/taikonline.sql"
# pg_dump ends with this line: without it the dump was cut short, and must not replace a good backup.
tail -n 5 "$work/taikonline.sql" | grep -q 'PostgreSQL database dump complete'

restic backup /backup/taikonline.sql --host taikonline --tag db
restic forget --host taikonline --tag db --keep-daily 30 --prune
echo "Backup finished $(date -Iseconds)"
