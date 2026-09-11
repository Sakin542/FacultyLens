# FacultyLens — Deployment, Operations & Recovery

## 1. Environments

| Environment | Purpose | Config | Data |
|---|---|---|---|
| development | local Docker stack (`docker-compose.yml`) | `backend/.env` from `.env.example`; `APP_ENV=local`, `APP_DEBUG=true` | `php artisan db:seed` (synthetic `DevelopmentSeeder`) |
| testing | PHPUnit / Vitest / pytest | `backend/phpunit.xml` (sqlite, array cache, sync queue), `backend/.env.testing` | factories + in-test fixtures |
| production | `docker-compose.prod.yml` | `backend/.env` from `.env.production.example`, `ai-service/.env`, compose `.env` from `.env.prod.example` | real data only; seeding refused (`DatabaseSeeder` guard) |

Secrets never enter git: `.env`, `.env.*` are ignored everywhere; only `*.example` templates are tracked
(`backend/.env.testing` holds development-only values used by the test runner).

Development URLs (never used in production config): backend `http://127.0.0.1:8080`, frontend Vite `http://localhost:5173`
(preview `:4173`), AI `http://127.0.0.1:8001`, MySQL `3307`, phpMyAdmin `8081`.

## 2. Production topology

```
Internet ──HTTPS──▶ nginx (facultylens/frontend image: static React + TLS + security headers + rate limits)
                       │ /api, /sanctum  → fastcgi
                       ▼
                    app (php-fpm, Laravel)  ──▶ mysql (internal only)
                       │                     ──▶ redis (cache + queue, password, internal only)
                       │                     ──▶ ai-service (FastAPI, X-AI-Service-Key, internal only, /ready gate)
                    worker (queue:work redis)   scheduler (schedule:work: reports:purge-expired)
```

Only nginx publishes ports (80 → 301 https, 443). MySQL, Redis and the AI service have no published ports.
The AI service never receives browser traffic; Laravel authenticates to it with `AI_SERVICE_API_KEY`
(`X-AI-Service-Key`), and FastAPI rejects requests without it (401). Hugging Face credentials (`HF_TOKEN`) live only in
`ai-service/.env`.

## 3. First deployment

```bash
git clone … && cd FacultyLens
cp .env.prod.example .env                                  # compose: DB/Redis passwords, image tag, TLS dir
cp backend/.env.production.example backend/.env            # APP_KEY, DB, Redis, AI key, mail, CORS, SANCTUM
cp ai-service/.env.production.example ai-service/.env      # same AI_SERVICE_API_KEY, DEBUG=false
mkdir -p deploy/certs && cp fullchain.pem privkey.pem deploy/certs/

docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml run --rm --no-deps app php artisan key:generate --show   # paste into backend/.env APP_KEY
docker compose -f docker-compose.prod.yml up -d mysql redis
docker compose -f docker-compose.prod.yml up -d app
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml up -d
```

The app entrypoint validates `APP_KEY`, waits for MySQL, then runs `config:cache`, `route:cache`, `view:cache`
(config is cached from the container environment, so fix `.env` *before* starting). Migrations are deliberately manual.

### Database user (least privilege)

```sql
CREATE USER 'facultylens_app'@'%' IDENTIFIED BY '…';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES ON facultylens.* TO 'facultylens_app'@'%';
```
(`DROP/ALTER/CREATE` are needed for migrations; remove them after go-live if migrations run with a separate account.)

## 4. Release procedure

```
Backup → Build images → Run tests → Deploy → Migrate → Cache config → Start workers → Health checks → Smoke tests → Monitor
```

```bash
scripts/backup.sh docker-compose.prod.yml /backups                       # 1. backup (DB + private storage + manifest)
IMAGE_TAG=$(git rev-parse --short HEAD) docker compose -f docker-compose.prod.yml build   # 2. build
(cd backend && php artisan test) && (cd frontend && npm test && npm run build) && (cd ai-service && pytest -q)   # 3. tests (CI)
IMAGE_TAG=… docker compose -f docker-compose.prod.yml up -d app           # 4. deploy API first
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force   # 5. forward-compatible migrations only
IMAGE_TAG=… docker compose -f docker-compose.prod.yml up -d               # 6. nginx, worker, scheduler, ai-service
curl -fsS https://<host>/api/health && curl -fsS https://<host>/api/health/ready   # 7. health
```

### Smoke test (after every deployment)

| Check | Expect |
|---|---|
| `GET /` | 200, SPA loads (no mixed content, CSP clean) |
| `GET /api/health`, `GET /api/health/ready` | 200 `ok` / `ready` |
| Login with a staff account | 200, `GET /api/auth/user` returns the profile |
| `GET /api/courses`, open a course, an assessment, a version, an analysis | 200 |
| `GET /api/analytics/overview` | 200 with real KPIs (or `N/A`, never fabricated) |
| `POST /api/reports/preview` → `POST /api/reports` (small PDF) → `GET /api/reports/{id}/download` | 201 / 200 `application/pdf`; `REPORT_DOWNLOADED` audit row |
| `docker compose ps` | all containers `healthy` |

## 5. Rollback

| Layer | Procedure |
|---|---|
| Application / frontend / AI images | `IMAGE_TAG=<previous> docker compose -f docker-compose.prod.yml up -d` (images are tagged by commit; keep the last 3 tags) |
| Configuration | restore previous `.env` files from the secret store; restart `app`, `worker`, `scheduler` (entrypoint re-caches config) |
| Queue | `docker compose exec app php artisan queue:restart`; failed jobs remain in `failed_jobs` for `queue:retry` after the fix |
| Database | **Do not** `migrate:rollback` on academic tables — migrations are additive/forward-compatible. Roll the application back to the previous tag (old code ignores new nullable columns/tables). Only if data corruption occurred: restore from the pre-deployment backup with `scripts/restore.sh <backup> --target-db facultylens --yes --with-storage` during an outage window. |

## 6. Backup & restore

- `scripts/backup.sh [compose-file] [dir]` — `mysqldump --single-transaction` (gzip), `storage/app` tarball (documents, reports), `manifest.json` (counts, git commit, SHA-256). Retention `BACKUP_RETENTION_DAYS` (default 14). Schedule daily (cron/systemd timer) and copy to off-host storage.
- `scripts/restore.sh <backup-dir>` — **restore test** into a scratch database: checksum → import → table/row counts vs manifest → orphan checks → `migrate:status` → drop scratch. Add `--target-db facultylens --yes [--with-storage]` for a real restore, then run `php artisan migrate --force` and `php artisan facultylens:integrity-check`.
- Verified on 2026-09-11 against the development stack: 72 tables, counts identical to the manifest, 0 orphans, 0 pending migrations (see `docs/PRODUCTION_READINESS.md`).

### Disaster recovery parameters (to be confirmed by the institution)

| Item | Value |
|---|---|
| RPO | ≤ 24 h with daily backups (reduce by running `backup.sh` more often; binlog shipping is not configured) |
| RTO | ~1 h: provision host, `docker compose up`, restore backup, migrate, smoke test |
| Backup location | off-host object storage / institutional backup target (operator-provided); backups exclude `.env` secrets |
| Responsible operator | institutional IT (owner) + FacultyLens maintainer (secondary) |
| Failure procedure | 1) declare incident, 2) stop `worker`/`scheduler`, 3) take a fresh backup of whatever is left, 4) restore per §6, 5) run integrity check, 6) smoke test, 7) post-incident review |

## 7. Data retention (defaults; institutional policy prevails)

| Data | Default | Notes |
|---|---|---|
| Documents, student submissions, grades, assessment versions, analyses, AI evaluation runs | retained (no automatic deletion) | academic records; delete only by explicit policy/manual action |
| Institutional report **files** | 7 days (`REPORTS_EXPIRATION_DAYS`) | report records + audit rows are kept |
| Audit logs | retained | tamper-resistant by design (append-only service) |
| Sessions | 120 min (`SESSION_LIFETIME`) | |
| Backups | 14 days locally (`BACKUP_RETENTION_DAYS`) | longer off-host retention per policy |

## 8. Monitoring & alerts

- **Health**: `/api/health` (liveness), `/api/health/ready` (readiness), `ai-service /ready`; compose healthchecks on every service.
- **Logs**: JSON via Docker `json-file` (20 MB × 5); Laravel `LOG_CHANNEL=stderr`. Every API request logs `api.request` with `request_id`, `user_id`, `route`, `status`, `duration_ms` (warnings for 4xx and requests slower than `LOG_SLOW_REQUEST_MS`). Correlate with the `X-Request-Id` response header. Bodies, cookies, tokens and documents are never logged.
- **Suggested alerts**: HTTP 5xx rate > 1 %/5 min; readiness ≠ `ready`; `failed_jobs` growth (`php artisan queue:monitor redis:default --max=1000` powers the worker healthcheck); AI `/ready` 503 > 5 min; report `FAILED` count; disk > 80 % (uploads, reports, MySQL, HF cache); MySQL/Redis unavailable.
- **AI monitoring**: request count/latency/error rate from `api.request` lines for `/api/ai/*`; model + prompt + generation versions are stored on every AI artifact; STEP 35 evaluation status is exposed in analytics and reports (“Not evaluated” when absent).
- **Integrity**: `php artisan facultylens:integrity-check --fail-on-issues` (read-only) — run nightly and after restores.

## 9. Queue

Production uses Redis queues with `queue:work redis --tries=2 --backoff=30 --timeout=900 --max-time=3600 --memory=512`.
Jobs: document extraction/embeddings, assessment analysis, question generation, grading assistance, performance analysis,
AI evaluation, institutional reports. All jobs have bounded tries/backoff and `failed()` handlers that persist a safe
status; none retry indefinitely. Laravel Horizon is optional: `composer require laravel/horizon`, add `HORIZON_*` env and
replace the `worker` command with `php artisan horizon` (the image already ships `pcntl`).

## 10. Operations cheat-sheet

```bash
docker compose -f docker-compose.prod.yml ps
docker compose -f docker-compose.prod.yml logs -f app worker
docker compose -f docker-compose.prod.yml exec app php artisan queue:failed
docker compose -f docker-compose.prod.yml exec app php artisan reports:purge-expired
docker compose -f docker-compose.prod.yml exec app php artisan facultylens:integrity-check
scripts/perf-smoke.sh <email> <password> 10      # latency snapshot of key endpoints
```

## 11. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| 419 on login | frontend origin not in `SANCTUM_STATEFUL_DOMAINS`/`CORS_ALLOWED_ORIGINS`, or `SESSION_DOMAIN` mismatch |
| CORS error in browser | origin missing from `CORS_ALLOWED_ORIGINS` (no wildcard with credentials) |
| “AI analysis is temporarily unavailable” | `ai-service` not ready (`/ready` 503 while the model loads) or key mismatch (401 in app logs) |
| Reports stuck `PENDING` | `worker` not running / Redis unreachable — check `docker compose ps`, `queue:failed` |
| `Failed to fetch dynamically imported module` after deploy | stale tab from a previous build; the SPA reloads itself once (`vite:preloadError`) |
| Config change not applied | config is cached at container start — restart `app`, `worker`, `scheduler` |
