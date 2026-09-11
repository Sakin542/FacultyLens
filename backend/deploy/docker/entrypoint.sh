#!/usr/bin/env bash
# FacultyLens backend entrypoint (production image).
# Caches configuration/routes/views from the container environment, then runs the requested command
# (php-fpm, queue worker or scheduler). Migrations are NOT run automatically — see docs/DEPLOYMENT.md.
set -euo pipefail

cd /var/www/html

if [[ -z "${APP_KEY:-}" ]]; then
  echo "APP_KEY is not set. Generate one with: php artisan key:generate --show" >&2
  exit 1
fi

# Wait for the database before caching config (fails fast after ~60s)
for i in $(seq 1 30); do
  if php -r 'try { new PDO(sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: 3306, getenv("DB_DATABASE")), getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_TIMEOUT => 2]); exit(0); } catch (Throwable $e) { exit(1); }'; then
    break
  fi
  echo "Waiting for database (${i}/30)..."
  sleep 2
done

php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php artisan view:cache >/dev/null
php artisan event:cache >/dev/null 2>&1 || true
# No storage:link on purpose: documents and reports live on the private disk and are streamed by authenticated endpoints only.

exec "$@"
