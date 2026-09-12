# FacultyLens Performance Test Environment

STEP 43 — all numbers in `docs/PERFORMANCE_TEST_REPORT.md` were produced on **this** environment. Do not compare them with
results from other hardware without noting the difference.

## 1. Host

| Item | Value |
|---|---|
| OS | Microsoft Windows 11 Pro 10.0.26200 |
| CPU | AMD Ryzen 7 7700 (8 cores / 16 threads) |
| RAM | 16 GB physical; **Docker Desktop VM limited to 16 vCPU / 7.3 GB** (`docker info`) |
| GPU | NVIDIA GeForce RTX 5060 Ti 8 GB — **not used**: the AI image runs `torch 2.14.0+cpu`; all inference numbers are CPU-only |
| Docker | Docker Desktop, engine 29.7.2 (linux/amd64, WSL2 backend `docker-desktop`) |
| Load generator | k6 v2.2.0 (windows/amd64) running **on the same host** as the containers — generator CPU competes with the stack at high VU counts; this is recorded, not hidden |

## 2. System under test — production topology, run locally

The development stack (`docker-compose.yml`, `php artisan serve`, bind mounts, no opcache) is **single-threaded** and was
explicitly *not* used for load numbers. The performance stack is `docker-compose.prod.yml` plus the override
`performance/docker-compose.perf.yml` (project `facultylens-perf`, isolated volumes, HTTP on :8090, MySQL :3309, AI :8011):

```
k6 ──HTTP──▶ nginx 1.27.5 (React build, no TLS, no per-IP limit_req)   ──fastcgi──▶ php-fpm 8.2.33 + opcache (Laravel 12.69.1, config/route/view cached)
                                                                                          ├──▶ MySQL 8.0.46  (innodb_buffer_pool 512M, max_connections 200)
                                                                                          ├──▶ Redis 7.4.11  (cache + queue, predis, maxmemory 256 MB allkeys-lru)
                                                                                          └──▶ FastAPI 0.141.1 / uvicorn 0.52.4, Python 3.11.16, torch 2.14.0+cpu,
                                                                                               sentence-transformers 6.0.1 — model sentence-transformers/all-MiniLM-L6-v2 (loaded once at startup)
worker: php artisan queue:work redis --tries=2 --backoff=30 --timeout=900 --max-jobs=500 --max-time=3600 --memory=512
scheduler: php artisan schedule:work
```

Differences from `deploy/nginx/facultylens.conf` (documented on purpose, see `performance/env/nginx.perf.conf`):

* plain HTTP — the load generator and the server share one machine; TLS handshake cost is not what is being measured;
* nginx `limit_req` zones (30 r/s per IP, 5 r/s auth) removed — a single-IP generator would only measure the limiter.
  Laravel's own throttles (120/min per user, 20 logins/min per IP, 30 AI-analysis/min, …) stay active; each virtual
  user has its **own account and its own synthetic client IP** (`X-Forwarded-For`, nginx is inside `TRUSTED_PROXIES`),
  which is how real faculty traffic looks. `rate-limits` checks use a single IP deliberately.

Horizon is **not installed** in FacultyLens (documented in STEP 40 as an optional add-on); queue measurements use the
Redis-backed `queue:work` worker that production runs.

## 3. Dataset (`php artisan db:seed --class=PerformanceDatasetSeeder`)

Bulk-inserted synthetic data (7 s), clearly fake identifiers, idempotent:

| Entity | Count |
|---|---|
| Faculty accounts | 1 owner + 100 virtual faculty (EDITOR collaborators on every perf course) + 1 owner of 1 000 courses |
| Courses | 10 (perf) + 1 000 (list/pagination test) |
| Learning outcomes | 50 |
| Assessments | 100 (10 per course; sizes 10/20/30/50/75/100/120/150/180/**200** questions) |
| Questions | 9 350 |
| Previous questions (question bank) | 5 000 |
| Completed analysis reports + recommendations | 100 + 200 |
| Students | 5 000 |
| Submissions (GRADED / FINALIZED) | 20 000 (200 per assessment) |
| Student answers (REVIEWED, marks) | 100 000 |

## 4. Versions (verified inside the containers)

| Component | Version |
|---|---|
| PHP | 8.2.33 NTS, opcache on, `memory_limit=512M` |
| Laravel | 12.69.1 |
| MySQL | 8.0.46 |
| Redis | 7.4.11 |
| nginx | 1.27.5 |
| Python / FastAPI / uvicorn | 3.11.16 / 0.141.1 / 0.52.4 |
| torch / sentence-transformers / transformers | 2.14.0+cpu / 6.0.1 / 5.17.0 |
| Hugging Face model | `sentence-transformers/all-MiniLM-L6-v2` |
| Node (frontend build) | 22.18.0 |
| k6 | 2.2.0 |

## 5. How to reproduce

```bash
# 1. stack
docker compose -p facultylens-perf --env-file performance/env/compose.env -f docker-compose.prod.yml -f performance/docker-compose.perf.yml up -d --build
docker compose -p facultylens-perf --env-file performance/env/compose.env -f docker-compose.prod.yml -f performance/docker-compose.perf.yml exec app php artisan migrate --force
docker compose -p facultylens-perf --env-file performance/env/compose.env -f docker-compose.prod.yml -f performance/docker-compose.perf.yml exec app php artisan db:seed --class=PerformanceDatasetSeeder --force
# 2. scenarios (see performance/README.md)
bash performance/run.sh auth.js
PROFILE=ramp bash performance/run.sh full-workflow.js
```
