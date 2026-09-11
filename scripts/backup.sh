#!/usr/bin/env bash
# FacultyLens backup: MySQL dump + private application storage (documents, reports) + config metadata.
#
#   scripts/backup.sh [compose-file] [backup-dir]
#   COMPOSE_FILE=docker-compose.prod.yml scripts/backup.sh
#
# Produces backups/facultylens_<UTC timestamp>/ with:
#   db.sql.gz              full logical dump (routines, triggers, single transaction)
#   storage.tar.gz         storage/app (private documents + institutional report files)
#   manifest.json          versions/counts for restore verification
# Secrets (.env) are NOT included on purpose — keep them in your secret store.
set -euo pipefail

COMPOSE_FILE="${1:-${COMPOSE_FILE:-docker-compose.yml}}"
BACKUP_ROOT="${2:-${BACKUP_DIR:-backups}}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
DEST="${BACKUP_ROOT}/facultylens_${STAMP}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
dc() { docker compose -f "$COMPOSE_FILE" "$@"; }

mkdir -p "$DEST"
echo "== Backup → $DEST (compose: $COMPOSE_FILE)"

DB_NAME=$(dc exec -T mysql sh -c 'echo "${MYSQL_DATABASE:-facultylens}"' | tr -d '\r')
echo "-- MySQL dump ($DB_NAME)"
dc exec -T mysql sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --quick --routines --triggers --set-gtid-purged=OFF "${MYSQL_DATABASE:-facultylens}"' | gzip -9 > "$DEST/db.sql.gz"

echo "-- Private storage (storage/app)"
dc exec -T app sh -c 'cd /var/www/html/storage && tar -czf - app 2>/dev/null' > "$DEST/storage.tar.gz"

echo "-- Manifest"
COUNTS=$(dc exec -T app php artisan tinker --execute='echo json_encode(["users"=>\App\Models\User::count(),"courses"=>\App\Models\Course::count(),"assessments"=>\App\Models\Assessment::count(),"questions"=>\App\Models\Question::count(),"assessment_versions"=>\App\Models\AssessmentVersion::count(),"student_submissions"=>\App\Models\StudentSubmission::count(),"student_answers"=>\App\Models\StudentAnswer::count(),"institutional_reports"=>\App\Models\InstitutionalReport::count(),"audit_logs"=>\App\Models\AuditLog::count()]);' 2>/dev/null | tr -d '\r' | tail -1)
GIT_SHA=$(git rev-parse --short HEAD 2>/dev/null || echo unknown)
cat > "$DEST/manifest.json" <<EOF
{"created_at":"$STAMP","compose_file":"$COMPOSE_FILE","database":"$DB_NAME","git_commit":"$GIT_SHA","counts":${COUNTS:-null},
 "files":{"db":"db.sql.gz","storage":"storage.tar.gz"},"db_sha256":"$(sha256sum "$DEST/db.sql.gz" | cut -d' ' -f1)","storage_sha256":"$(sha256sum "$DEST/storage.tar.gz" | cut -d' ' -f1)"}
EOF

echo "-- Retention: removing backups older than ${RETENTION_DAYS} days"
find "$BACKUP_ROOT" -maxdepth 1 -type d -name 'facultylens_*' -mtime +"$RETENTION_DAYS" -exec rm -rf {} + 2>/dev/null || true

du -sh "$DEST"/* | sed 's/^/   /'
echo "== Done. Copy $DEST to off-host storage (object storage / institutional backup target)."
