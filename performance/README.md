# FacultyLens performance & load tests (STEP 43)

k6 scenarios that run against the **production topology started locally** (nginx → php-fpm → MySQL / Redis / FastAPI,
Redis queue worker). Results and the full analysis live in `docs/PERFORMANCE_TEST_REPORT.md`; the environment is
described in `docs/PERFORMANCE_TEST_ENVIRONMENT.md`.

```
performance/
├── docker-compose.perf.yml   override for docker-compose.prod.yml (isolated project facultylens-perf, :8090)
├── env/                      throw-away env files + HTTP-only nginx config for the perf stack
├── load/
│   ├── lib/session.js        Sanctum session helper (one account + one synthetic client IP per VU), metrics, summary
│   ├── lib/profiles.js       PROFILE=baseline|ramp|spike|soak|fixed
│   ├── auth.js               login / me / logout                                   §7
│   ├── dashboard.js          analytics overview (cache hit + forced miss), course analytics §5 §14 §15
│   ├── courses.js            list (10 & 1 000 courses), show, create/update/delete   §8
│   ├── assessments.js        10/50/100/200-question assessments, analysis read, question bank (5 000) §9 §10
│   ├── analytics.js          → dashboard.js covers it (kept as alias)             §14
│   ├── ai-analysis.js        MODE=direct FastAPI latencies · MODE=app async analysis §16–§20
│   ├── reports.js            preview → create (PDF/CSV/XLSX) → download            §24
│   ├── queue.js              Redis queue throughput + job idempotency              §25–§27
│   ├── rate-limits.js        429 behaviour of every throttle from one IP/account   §36
│   └── full-workflow.js      representative faculty journey (ramp/spike/soak)     §31–§33
├── run.sh                    run one scenario while sampling `docker stats`, MySQL connections, Redis memory/queue
├── capacity.sh               fixed-VU ladder 1/5/10/25/50/100 → capacity table
├── snapshot-academic.sh      academic-correctness snapshot (diff before/after optimizations) §40
├── soak-drift.py             first-third vs last-third memory/CPU drift from a *.stats.csv    §33
├── timeout-test.sh           pauses the AI container → observes AI_SERVICE_TIMEOUT, job retry, API responsiveness §37
├── summarize.py              compact table for one or more result files
├── profile-analytics.php     per-query timing of /analytics/overview (tinker, read-only)
└── results/                  machine-readable k6 summaries (*.json) + resource samples (*.stats.csv) + academic snapshots
```

The real-browser page-timing probe lives in `frontend/tests/perf/page-timings.spec.ts`
(`cd frontend && npx playwright test tests/perf/page-timings.spec.ts --config tests/perf/playwright.perf.config.ts`)
and writes `results/frontend-pages.json`.

## Start the stack

```bash
P="docker compose -p facultylens-perf --env-file performance/env/compose.env -f docker-compose.prod.yml -f performance/docker-compose.perf.yml"
$P up -d --build
$P exec app php artisan migrate --force
$P exec app php artisan db:seed --class=PerformanceDatasetSeeder --force   # 10 courses, 100 assessments, 100k answers, 100 virtual faculty …
curl -s http://127.0.0.1:8090/api/health/ready
```

## Run

```bash
bash performance/run.sh auth.js                              # 1 VU baseline (default PROFILE=baseline, ITERATIONS=30)
PROFILE=ramp  bash performance/run.sh full-workflow.js       # 1 → 100 VUs
PROFILE=spike bash performance/run.sh full-workflow.js       # 10 → 100 → 10
PROFILE=soak SOAK_MINUTES=30 bash performance/run.sh full-workflow.js
bash performance/capacity.sh after                           # fixed-VU ladder, prints the capacity table
bash performance/run.sh ai-analysis.js -e MODE=direct        # FastAPI only
bash performance/run.sh ai-analysis.js -e MODE=app -e AI_CONCURRENCY=5
bash performance/run.sh queue.js -e JOBS=10
bash performance/run.sh rate-limits.js -e SINGLE_IP=1
bash performance/run.sh reports.js
bash performance/snapshot-academic.sh before && bash performance/snapshot-academic.sh after && diff -rq performance/results/academic-before performance/results/academic-after
bash performance/timeout-test.sh                             # PAUSE_FOR=130 for the hard-timeout path
python performance/summarize.py performance/results/*-after.json
python performance/soak-drift.py performance/results/full-workflow-soak-after.stats.csv
```

Variables: `BASE_URL` (default `http://127.0.0.1:8090`), `AI_BASE_URL` (`:8011`), `RUN_TAG` (suffix for result files),
`THINK_TIME` (seconds between iterations), `IP_PER_ITER=1` (new client IP every iteration — login storms),
`SINGLE_IP=1` (all traffic from one IP — throttle tests), `DEBUG_STATUS=1` (log every non-2xx response).

## Rules

* Never point these scripts at a real deployment — they create courses/reports and pause containers.
* `env/` holds perf-only credentials (`perf_*`, `perf-ai-key-not-secret`) and a throw-away APP_KEY; `AI_SERVICE_API_KEY`
  must be identical in `env/backend.env` and `env/ai.env` or queued analyses fail with 401.
* `run.sh` fails (non-zero) when k6 thresholds are crossed (`http_req_failed < 5 %`).
* Every number in the report comes from a file in `performance/results/`; nothing is estimated.
