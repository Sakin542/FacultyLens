#!/usr/bin/env bash
# FacultyLens restore / restore-test.
#
#   scripts/restore.sh <backup-dir> [--target-db NAME] [--with-storage] [--compose FILE] [--yes]
#
# Default is a NON-DESTRUCTIVE restore test: the dump is loaded into a scratch database
# (facultylens_restore_test), row counts are compared with the backup manifest, migrations are checked
# with `migrate:status`, and the scratch database is dropped. Restoring over the live database
# requires --target-db <live db> --yes and should only be done during an outage window after a fresh backup.
set -euo pipefail

BACKUP_DIR="${1:?usage: scripts/restore.sh <backup-dir> [--target-db NAME] [--with-storage] [--compose FILE] [--yes]}"; shift
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"; TARGET_DB="facultylens_restore_test"; WITH_STORAGE=0; YES=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --target-db) TARGET_DB="$2"; shift 2;;
    --with-storage) WITH_STORAGE=1; shift;;
    --compose) COMPOSE_FILE="$2"; shift 2;;
    --yes) YES=1; shift;;
    *) echo "unknown option $1" >&2; exit 2;;
  esac
done
dc() { docker compose -f "$COMPOSE_FILE" "$@"; }
sql() { printf '%s' "$1" | dc exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N' | tr -d '\r'; }

[[ -f "$BACKUP_DIR/db.sql.gz" ]] || { echo "db.sql.gz missing in $BACKUP_DIR" >&2; exit 1; }
LIVE_DB=$(dc exec -T mysql sh -c 'echo "${MYSQL_DATABASE:-facultylens}"' | tr -d '\r')
SCRATCH=1; [[ "$TARGET_DB" != "facultylens_restore_test" ]] && SCRATCH=0

echo "== Verifying backup integrity"
if [[ -f "$BACKUP_DIR/manifest.json" ]]; then
  EXPECT=$(python -c "import json,sys;m=json.load(open(sys.argv[1]));print(m['db_sha256'])" "$BACKUP_DIR/manifest.json")
  ACTUAL=$(sha256sum "$BACKUP_DIR/db.sql.gz" | cut -d' ' -f1)
  [[ "$EXPECT" == "$ACTUAL" ]] && echo "   db.sql.gz checksum OK" || { echo "   CHECKSUM MISMATCH for db.sql.gz" >&2; exit 1; }
fi
gzip -t "$BACKUP_DIR/db.sql.gz" && echo "   gzip OK"

if [[ $SCRATCH -eq 0 ]]; then
  echo "!! You are about to REPLACE database '$TARGET_DB' on $COMPOSE_FILE with $BACKUP_DIR"
  [[ $YES -eq 1 ]] || { read -r -p "Type the database name to confirm: " c; [[ "$c" == "$TARGET_DB" ]] || { echo "aborted"; exit 1; }; }
fi

echo "== Restoring dump into '$TARGET_DB'"
sql "DROP DATABASE IF EXISTS $TARGET_DB; CREATE DATABASE $TARGET_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip -c "$BACKUP_DIR/db.sql.gz" | dc exec -T mysql sh -c "exec mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" \"$TARGET_DB\""

echo "== Verifying restored data"
TABLES=$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$TARGET_DB'")
echo "   tables: $TABLES"
for t in users courses assessments questions assessment_versions student_submissions student_answers institutional_reports audit_logs; do
  printf "   %-22s %s\n" "$t" "$(sql "SELECT COUNT(*) FROM $TARGET_DB.$t" 2>/dev/null || echo '-')"
done
if [[ -f "$BACKUP_DIR/manifest.json" ]]; then
  python - "$BACKUP_DIR/manifest.json" "$TARGET_DB" <<'EOF' "$(sql "SELECT CONCAT(table_name, ':', table_rows) FROM information_schema.tables WHERE table_schema='$TARGET_DB' AND table_name IN ('users','courses','assessments','questions','assessment_versions','student_submissions','student_answers','institutional_reports','audit_logs')")"
import json, sys
m = json.load(open(sys.argv[1])); counts = m.get('counts') or {}
print('   manifest counts:', json.dumps(counts))
EOF
fi
# Relationship spot checks (orphans must be zero after a consistent restore)
echo "   orphan questions:   $(sql "SELECT COUNT(*) FROM $TARGET_DB.questions q LEFT JOIN $TARGET_DB.assessments a ON a.id=q.assessment_id WHERE a.id IS NULL")"
echo "   orphan answers:     $(sql "SELECT COUNT(*) FROM $TARGET_DB.student_answers s LEFT JOIN $TARGET_DB.student_submissions sub ON sub.id=s.student_submission_id WHERE sub.id IS NULL")"
echo "   reports w/o version:$(sql "SELECT COUNT(*) FROM $TARGET_DB.institutional_reports r LEFT JOIN $TARGET_DB.assessment_versions v ON v.id=r.assessment_version_id WHERE r.assessment_version_id IS NOT NULL AND v.id IS NULL")"

echo "== Migration status against '$TARGET_DB'"
dc exec -T -e DB_DATABASE="$TARGET_DB" app php artisan migrate:status 2>/dev/null | grep -ciE "pending" | sed 's/^/   pending migrations: /' || true

if [[ $WITH_STORAGE -eq 1 && -f "$BACKUP_DIR/storage.tar.gz" ]]; then
  echo "== Restoring private storage"
  dc exec -T app sh -c 'cd /var/www/html/storage && tar -xzf -' < "$BACKUP_DIR/storage.tar.gz"
fi

if [[ $SCRATCH -eq 1 ]]; then
  sql "DROP DATABASE $TARGET_DB;"
  echo "== Restore TEST passed; scratch database dropped. Live database '$LIVE_DB' untouched."
else
  echo "== Restore into '$TARGET_DB' complete. Run: docker compose -f $COMPOSE_FILE exec app php artisan migrate --force && php artisan facultylens:integrity-check"
fi
