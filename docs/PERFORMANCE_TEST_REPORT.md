# FacultyLens Performance Test Report

STEP 43 — Performance & Load Testing. Every number in this document was produced by a run whose raw output is in
`performance/results/` (k6 summaries as JSON, `docker stats` samples as `*.stats.csv`, run logs as `_log_*.txt`).
Nothing below is estimated or extrapolated; where a subsystem was **not** measured it is marked `NOT_TESTED`.

Companion documents: [PERFORMANCE_TEST_ENVIRONMENT.md](PERFORMANCE_TEST_ENVIRONMENT.md) (host, topology, dataset,
versions, reproduction) and [performance/README.md](../performance/README.md) (scenario catalogue).

---

## 1. Test Environment

| Item | Value (verified) |
| --- | --- |
| Host | Windows 11 Pro, AMD Ryzen 7 7700 (8C/16T), 16 GB RAM, NVMe. GPU not used (torch CPU build). |
| Docker Desktop | 29.7.2, Linux VM with 16 vCPU / 7.3 GB RAM — all containers share this budget with the load generator (k6 runs on the host). |
| Topology | Production compose (`docker-compose.prod.yml`) + `performance/docker-compose.perf.yml`: nginx 1.27.5 → php-fpm 8.2.33 (opcache) → MySQL 8.0.46 / Redis 7.4.11; `queue:work redis` worker; scheduler; FastAPI AI service (Python 3.11.16, torch 2.14.0+cpu, sentence-transformers 6.0.1, `all-MiniLM-L6-v2`, single uvicorn worker). Project `facultylens-perf`, nginx on :8090 (HTTP, nginx `limit_req` disabled so Laravel throttles are what is measured). |
| Dataset | `PerformanceDatasetSeeder`: 10 courses, 100 assessments (10 … 200 questions), 9 350 questions, 5 000 previous questions, 5 000 students, 20 000 submissions, 100 000 student answers, 1 000 courses for a list-owner account, 100 virtual faculty accounts. |
| Isolation | Separate volumes, DB, Redis and network from the dev stack. Dev stack (`artisan serve`, single-threaded) was **not** used for any concurrency number. |
| Caveat | Load generator, browser probe and all seven containers share one machine; absolute latencies are therefore pessimistic at high VU counts and CPU % values are "of one vCPU" (e.g. 952 % = 9.5 vCPUs). |

## 2. Test Tools

| Tool | Version | Used for |
| --- | --- | --- |
| k6 | 2.2.0 | All HTTP load scenarios (`performance/load/*.js`), summaries via `handleSummary` → JSON |
| `performance/run.sh` | — | Wraps k6, samples `docker stats` every 2 s (CSV), records MySQL `Max_used_connections`, Redis memory and queue depth |
| `performance/capacity.sh` | — | Fixed-VU ladder 1/5/10/25/50/100 (45 s each) |
| `performance/summarize.py`, `soak-drift.py` | — | Table rendering; first-third vs last-third resource drift for soak |
| `performance/snapshot-academic.sh` | — | Normalised JSON snapshots of academic outputs (analysis, analytics, CO/PO, performance, AI direct) for correctness comparison |
| `performance/timeout-test.sh` | — | Dependency-stall test (pauses the AI container) |
| `performance/profile-analytics.php` | — | Per-statement SQL timing of the analytics overview inside the container |
| Playwright | 1.x (frontend devDependency) | `frontend/tests/perf/page-timings.spec.ts` — real Chromium page loads against the perf stack's built SPA |
| PHPUnit | Laravel 12 | `tests/Feature/Performance/*` — query-count (N+1), cache-correctness, rate-limit, wide-column regression tests |
| MySQL `EXPLAIN`, `information_schema`, `SHOW STATUS` | 8.0.46 | Index / plan verification |

## 3. Performance Targets

Targets were set before optimisation from the product's use (single faculty member working on one course at a time,
departments of tens of faculty, semester-end peaks):

| Area | Target |
| --- | --- |
| Simple authenticated reads (course/assessment/list) | p95 < 300 ms at 1 VU, p95 < 1 s at 50 VU |
| Analytics overview | p95 < 1 s cached, < 2 s cold at 1 VU |
| Login | p95 < 500 ms at 1 VU (bcrypt cost dominates) |
| Async AI analysis (200-question paper) | accepted < 500 ms; completed < 30 s end-to-end |
| Error rate under any tested load | 0 % 5xx |
| Soak (30 min) | no memory growth trend in app/worker/AI containers, 0 errors |
| Rate limits | behave as configured (`auth` 20/min/IP, `api` 120/min/user, `ai-analysis` 30/min/user, `uploads` 25/min/user) |
| Academic outputs | byte-identical before/after optimisations on unchanged data |

## 4. Baseline Results

Baseline = 1 VU, 30 iterations, think time so that no Laravel throttle is hit, taken **before** any optimisation
(`*-before.json`). Median / p95 in ms.

| Endpoint | med | p95 | note |
| --- | --- | --- | --- |
| `POST /api/auth/login` | 161 | 168 | bcrypt + session; `auth-baseline-before.json` |
| `GET /api/auth/user` | 10 | 15 | |
| `GET /api/courses` (10 courses) | 18 | 25 | |
| `GET /api/courses` (1 000 courses, list owner) | 88 | 116 | 370 KB, unpaginated (see §19) |
| `GET /api/assessments?per_page=100` | 29 | 39 | |
| `GET /api/assessments/{200 q}` | 37 | 50 | 175 KB |
| `GET /api/ai/assessments/{200 q}/analysis` | 38 | 46 | ~840 KB current analysis payload |
| `GET /api/analytics/overview` (warm cache) | **113** | 142 | |
| `GET /api/analytics/overview` (cold, cache flushed) | **625** | 647 | |
| `GET /api/analytics/courses/{id}` | 32 | 128 | |
| `GET /api/reports` / preview | 45 / 25 | 53 / 44 | |
| `POST /api/reports` (single assessment, PDF) | 48–56 | 258–797 | PDF 850–920 KB written synchronously |

Capacity ladder before optimisation (`full-workflow-fixed-before-vu*.json`, 45 s per step, 5 s think time, journey =
login-once + 9 reads + occasional report):

| VUs | req/s | journey p50 | journey p95 | overview p95 | login p95 | errors |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | 1.7 | 361 | 788 | 425 | 162 | 0 |
| 10 | 15.1 | 753 | 1 899 | 1 028 | 546 | 0 |
| 25 | 34.2 | 1 454 | 5 211 | 2 478 | 1 214 | 0 |
| 50 | 52.3 | 3 032 | 9 475 | 4 363 | 1 956 | 0 |
| 100 | 77.6 | 5 279 | 17 071 | 8 176 | 3 450 | 0 |

Throughput saturated at ≈78 req/s while the app container never exceeded 4.4 vCPUs and MySQL never used more than
**7 connections** — the first sign that requests were queuing in php-fpm rather than in MySQL (see B1).

## 5. API Performance

After optimisation (1 VU, `*-after.json`):

| Endpoint | med | p95 | payload |
| --- | --- | --- | --- |
| login / logout / auth user | 158 / 13 / 10 | 165 / 17 / 15 | |
| courses list (10) / course show / course create | 13 / 15 / 14 | 18 / 17 / 21 | |
| courses list (1 000) | 86 | 116 | 370 KB |
| assessments list (100) / history / versions | 30 / 27 / 15 | 39 / 36 / 19 | |
| assessment show 10 / 50 / 100 / 200 q | 17.5 / 22.5 / 27 / 37 | 21 / 24 / 29 / 48 | 200 q = 175 KB |
| analysis read 10 / 50 / 100 / 200 q | 17.5 / 22 / 28 / 39 | 24 / 28 / 33 / 46 | 200 q ≈ 840 KB |
| analysis status / history / recommendations | 16 / 15 / 16 | 19 / 19 / 20 | |
| submissions list / summary / performance | 18 / 16.5 / 15 | 21.5 / 20 / 19 | |
| question bank list / search (5 000 rows) | 16 / 14 | 20 / 15 | paginated |
| blueprint read | 13 | 16 | |
| analytics overview warm / cold | **38 / 537** | 52 / 540 | |
| analytics course / sections | 22 / 22 | 111 / 25 | |
| notifications | 13 | 16 | |

Latency scales with payload size, not with row count: 200-question responses cost ~2× the 10-question ones and the
`QueryCountTest` shows flat query counts (§6). Every read endpoint met the 1-VU target. The only endpoint outside the
target is the **cold** analytics overview (537 ms; target < 2 s cold — met, but it is the slowest request in the system).

## 6. Database Performance

**N+1 audit** — `tests/Feature/Performance/QueryCountTest.php` executes 12 endpoints at a small and a large N and asserts
the SQL statement count does not grow (all pass):

| Endpoint | queries @ small N | queries @ large N |
| --- | --- | --- |
| GET /courses | 1 | 1 (N=30) |
| GET /assessments?per_page=100 | 4 | 4 (N=40) |
| GET /assessments/history | 3 | 3 |
| GET /assessments/{id} | 7 | 7 (200 q) |
| GET /ai/assessments/{id}/analysis | 13 | 13 (200 q) |
| GET /assessments/{id}/analysis-history | 4 | 4 (15 versions) |
| GET /analytics/overview | 79 | 79 (10 courses) |
| GET /analytics/courses/{id} | 80 | 80 (12 assessments) |
| GET /courses/{id}/previous-questions | 3 | 3 (100) |
| GET /assessments/{id}/submissions | 5 | 5 (40) |
| GET /assessments/{id}/submissions/summary | 6 | 6 |
| GET /assessments/{id}/performance | 4 | 4 |

The analytics endpoints issue ~80 statements per cold request. They are flat in N (aggregates), individually fast
(profiled with `profile-analytics.php`: the single slow statement was the `student_answers` sub-select in the cache
data-version — 76 ms on 100 000 rows — fixed in B2) and the result is cached, so this was not reduced further (see §19).

**Indexes** (from `information_schema.STATISTICS` on the perf DB) — every access path used by the hot endpoints is
covered: `assessments(course_id,status)`, `questions(assessment_id,question_number)`, `previous_questions(course_id,source)`,
`student_submissions(assessment_id)`, `(assessment_id,student_id)` unique, `student_answers(student_submission_id,question_id)`
unique, `(question_id)`, `analysis_reports(assessment_id,is_current)`, `(assessment_id,analyzed_at)`, `(assessment_id,analysis_version)`,
`courses(user_id,status)`, `learning_outcomes(course_id,sort_order)`, `users(email)`, `notifications(notifiable_type,notifiable_id)`.

**EXPLAIN** on the hot query shapes: all use `ref`/`eq_ref` index lookups (e.g. current analysis report for an
assessment: `ref` on `analysis_reports_assessment_id_is_current_index`; 200 questions: `ref` 200 rows on the composite
index; per-question averages: `student_submissions` index → `student_answers` unique index, 200 × 4 rows). The only
full scans are on `courses` (1 013 rows) and `assessments` (102 rows) when a faculty's whole list is ordered by
`created_at` — tables this small are cheaper to scan than to sort via index, and no index was added (spec: only add
indexes measurements justify).

**Server counters after the whole campaign** (1.04 M statements): `Slow_queries 0` (long_query_time 10 s),
`Created_tmp_disk_tables 0`, `Select_full_join 1`, InnoDB buffer pool 512 MB with 576 M read requests vs 3 425 disk reads
(hit ratio > 99.99 %). Largest tables: `analysis_reports` 23.6 MB (231 rows — findings JSON), `student_answers` 23.6 MB
(99 405 rows).

**Connections**: max used 7 before B1, 33–35 after (limit 200) at 100 VUs.

**One real DB failure found**: MySQL error 1038 *Out of sort memory* on `SELECT * FROM analysis_reports … ORDER BY … LIMIT`
once several large findings JSON documents existed (B5, fixed with narrow column lists + regression test
`AnalysisReportWideColumnTest`).

## 7. AI Performance

**Model loading**: `all-MiniLM-L6-v2` (dim 384) preloaded at startup; 10.1 s on first start, 14.1 s after the image
rebuild (log timestamps `Loading Hugging Face model` → `Successfully loaded`). `/ready` reports `model_loaded: true`
only after preload, so the first request never pays the load cost. Resident memory ≈ 440–510 MB.

**Direct FastAPI calls** (`ai-analysis.js MODE=direct`, `ai-analysis-direct-baseline-after*.json`), median ms, CPU inference:

| Operation | without embedding cache | with cache, first call | with cache, repeated call (min) |
| --- | --- | --- | --- |
| embed 1 / 10 / 50 / 100 / 200 texts | 13 / 28 / 97 / 181 / 385 | 34 / 51 / 114 / 128 / 305 | **2 / 3 / 6 / 19 / 15** |
| similarity 20 q vs 100 / 500 / 1 000 / 5 000 previous | 222 / 984 / 1 928 / 8 851 | ≈ same | **7 / 25 / 62 / 175** |
| full analyze-assessment 10 / 50 / 100 / 200 q (500 previous) | 883 / 1 078 / 1 282 / 1 579 | ≈ same | **46 / 64 / 64 / 97** |

The AI container used 8.1 vCPUs on average (peak 8.5) during the cold direct run — torch already parallelises across
cores, so the single uvicorn worker is CPU-bound, not thread-bound. The embedding cache (B4) removes the inference cost
entirely for texts that have been seen (previous-question banks are re-embedded on every analysis of every assessment
in a course — exactly the repeated workload).

**Through Laravel, async** (`POST /ai/assessments/{id}/analyze?async=1` → poll `analysis-status`; sizes 10/50/100/200 q
round-robin; `ai-analysis-app-c*-baseline-after*.json`):

| concurrent analyses | accept med / p95 (ms) | end-to-end med / p95 / max (s) | 5xx |
| --- | --- | --- | --- | --- |
| 1 | 142 / – | 4.3 / – / 4.3 | 0 |
| 5 | 34 / 41 | 6.1 / 8.2 / 8.2 | 0 |
| 10 | 57 / 70 | 4.1 / 6.1 / 6.1 | 0 |
| 20 | 70.5 / 91.6 | 6.2 / 10.3 / 12.2 | 0 |

Queue pick-up (accept → first `processing` seen) is ≈2.1 s because the poll interval is 2 s; with a single
`queue:work` process analyses are serialised, so the p95 grows linearly with concurrency (20 requests ⇒ 12 s for the
last one). The status endpoint stayed at 20–28 ms median while the worker was busy.

Question generation and rubric generation call the same AI service; no generation model is configured in this
environment (`NOT_TESTED`, see §19). Document processing (upload → parse) was not load-tested (`NOT_TESTED`).

## 8. Queue/Horizon Performance

Horizon is **not installed**; the production compose runs `php artisan queue:work redis` (one process). Measured with
`queue.js` (dispatches async analyses and measures wall time):

| Run | result |
| --- | --- |
| 10 jobs from one account | all completed in **2.50 s** wall (≈240 jobs/min for 10-question analyses); accept med 27.5 ms |
| 50 jobs from 5 accounts | processing per job med 6.0 s / p95 10.0 s; 61 s wall. The poll loop hit the per-user `ai-analysis` 30/min throttle (551 × 429 on status polls — counted as failed requests in that JSON, 0 × 5xx), so the 61 s wall is an upper bound |
| idempotency | a duplicate `POST …/analyze?async=1` while a run is in flight returns 202 with the same `analysis_id`; `idempotency_duplicate_reports = 0` |
| Redis queue depth during soak / spike | 0 (jobs consumed as fast as produced) |

Configuration fixes (B7): `retry_after` (90 s) was shorter than the job timeouts (`AnalyzeAssessmentJob` 180 s, report
jobs up to 1 800 s), which would re-deliver long jobs while still running; raised to 1 900 s. `block_for` 5 s reduces
idle polling.

## 9. Redis Performance

`maxmemory 256 MB`, `allkeys-lru`. Cache DB used for analytics/analysis payload caches, sessions and rate-limiter keys.

| Phase | used memory | evictions | notes |
| --- | --- | --- | --- |
| Ramp before optimisation (100 virtual faculty each reading a 115 KB analysis payload) | **254.7 MB (peak 263.5 MB)** | **87** | per-user analysis cache keys → memory exhausted (B3) |
| After B3 (shared per-assessment key) — spike 100 VUs | 65 MB peak | 0 | |
| Soak 30 min | 40 → 25 MB (first vs last third), peak 80 MB | 0 | |
| End of campaign | 1.5 MB, `evicted_keys 0` since restart, hit ratio 96 % (361 311 hits / 14 623 misses) | | |

Redis CPU never exceeded 7.6 % of one vCPU.

## 10. Frontend Performance

Build served by the perf nginx (Vite production build): 47 JS chunks totalling **1 325 KB** (344 KB gzip); main chunk
`index-*.js` **630 KB (159 KB gzip)**, CSS 95 KB; route chunks are code-split (Analysis 79 KB, SubmissionDetails 69 KB,
AcademicAnalytics 57 KB …).

Real Chromium probe (`frontend/tests/perf/page-timings.spec.ts`, logged-in session, cold navigation per page, waits for
the SPA session splash and 1.5 s of API quiet; `frontend-pages.json`):

| Page | wall ms (minus 1.5 s splash) | API calls | API wait total | API bytes | DOM nodes |
| --- | --- | --- | --- | --- | --- |
| Dashboard | 1 957 | 5 | 124 | 109 KB | 832 |
| Courses | 1 645 | 4 | 68 | 4 KB | 867 |
| Course detail | 1 644 | 5 | 88 | 6 KB | 536 |
| Assessments | 1 675 | 5 | 93 | 17 KB | 1 754 |
| Assessment (200 q) | 1 950 | 7 | 179 | 191 KB | **6 605** |
| Analysis (200 q) | 1 934 | 8 | 221 | **840 KB** | 1 401 |
| Analytics | 1 997 | 5 | 117 | 120 KB | 4 248 |
| History | 1 693 | 7 | 131 | 24 KB | 1 026 |
| Reports | 1 646 | 4 | 89 | 1 KB | 306 |
| Question bank (5 000) | 1 698 | 5 | 93 | 11 KB | 702 |

No duplicate API requests on any page; no 5xx. FCP 32–48 ms and `load` ≤ 20 ms on every page (assets are local and
cached). The ≈1.6–2.0 s wall is dominated by the deliberate ≥1.5 s session splash plus the 1.5 s quiet-window of the
probe itself; actual API waiting is 68–221 ms per page.

## 11. Concurrent User Test

Fixed-VU ladder after optimisation (`full-workflow-fixed-after-vu*.json`; each VU = one faculty account with its own
session and synthetic client IP; 5 s think time; 45 s per step):

| VUs | req/s | journey p50 | journey p95 | overview p95 | analysis read p95 | assessment show p95 | login p95 | errors |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 1.7 | 286 | 728 | 360 | 46 | 38 | 164 | 0 |
| 5 | 8.4 | 279 | 1 064 | 654 | 40 | 40 | 165 | 0 |
| 10 | 16.9 | 260 | 895 | 439 | 49 | 51 | 333 | 0 |
| 25 | 40.4 | 331 | 2 430 | 1 176 | 117 | 120 | 533 | 0 |
| 50 | 76.8 | 379 | 3 465 | 1 533 | 182 | 237 | 750 | 0 |
| 100 | **139.7** | 490 | 7 263 | 2 905 | 311 | 300 | 1 374 | 0 |

Resources at 100 VUs: app 3.3 vCPU average / 9.5 peak, 207 MB; MySQL 2.3 / 5.2 vCPU, 703 MB; 33 connections.
Interpretation: median experience stays under 0.5 s up to 100 simultaneous faculty; p95 is driven by cold
analytics-overview requests (each VU's first dashboard load recomputes ~80 aggregate queries) colliding on 16 shared
vCPUs. The 50 VU step meets the "< 1 s p95 simple reads" target (analysis 182 ms, assessment 237 ms, list 187 ms);
the 100 VU step does not (300–505 ms p95 for reads, 2.9 s for overview).

## 12. Spike Test

Profile 10 VUs (60 s) → **100 VUs within 10 s** (hold 60 s) → 10 VUs (60 s) → 0; run after optimisation
(`full-workflow-spike-after.json`, 10 748 requests, 1 263 journeys):

| Metric | value |
| --- | --- |
| Errors / 5xx / 429 | **0 / 0 / 0** |
| Journey p50 / p95 / p99 | 451 / 8 542 / 9 372 ms |
| Overview med / p95 | 64 / 3 295 ms |
| Login (100 fresh sessions in the burst) med / p95 | 408 / 970 ms |
| Assessment show / analysis read p95 | 413 / 423 ms |
| App container peak | 9.4 vCPU, 1.05 GB (32 php-fpm children all busy) |
| MySQL max connections | 35 |
| Redis peak | 65 MB |
| Recovery | latencies back to the 10 VU level within ~40 s of the burst ending (stats CSV) |

The burst is absorbed without failures; the cost is latency (p95 8.5 s during the 60 s at 100 VUs), consistent with §11.

## 13. Soak Test

20 VUs for 30 min after optimisation (`full-workflow-soak-after.json` + `.stats.csv`, 59 370 requests, 7 065 journeys,
≈33 req/s):

| Metric | value |
| --- | --- |
| Errors / 5xx / 429 | **0 / 0 / 0** |
| Journey med / p95 / p99 / max | 191 / 314 / 753 / 910 ms |
| Overview med / p95 / p99 | 39 / 46 / 580 ms (p99 = cache refreshes every 5 min) |
| Assessment show / analysis read / courses list p95 | 39 / 39 / 18 ms |
| Report create (small PDF) med / p95 | 40 / 47 ms (1 405 reports) |

Resource drift, first third vs last third of the run (`soak-drift.py`):

| Container | memory first → last | CPU first → last |
| --- | --- | --- |
| app (php-fpm) | 112 → 114 MB (+2) | 43.2 % → 44.7 % |
| worker | 44 → 44 MB | 0.4 % → 0.3 % |
| ai-service | 469 → 469 MB | 0.2 % → 0.3 % |
| mysql | 932 → 939 MB (+8, buffer pool warming) | 24.6 % → 27.8 % |
| redis | 40 → 25 MB (TTL expiry) | 2.7 % → 2.6 % |
| nginx | 17 → 17 MB | 1.8 % → 1.7 % |

No memory leak or latency drift; MySQL connections peaked at 35 and were back to 2 at the end; Redis queue depth 0.

## 14. Resource Usage

| Scenario | app CPU avg / peak | app mem peak | MySQL CPU avg / peak | MySQL mem | AI CPU / mem | Redis mem |
| --- | --- | --- | --- | --- | --- | --- |
| 1 VU baselines | < 0.3 vCPU | ~100 MB | < 0.3 vCPU | 610 MB | idle / 440 MB | < 40 MB |
| 25 VUs (after) | 0.84 / 5.0 vCPU | 144 MB | 0.84 / 4.1 vCPU | 665 MB | idle | – |
| 50 VUs (after) | 1.5 / 7.9 vCPU | 387 MB | 2.1 / 6.7 vCPU | 676 MB | idle | – |
| 100 VUs (after) | 3.3 / 9.5 vCPU | 207 MB | 2.3 / 5.2 vCPU | 703 MB | idle | 65 MB |
| Spike 100 VUs | 1.6 / 9.4 vCPU | 1.05 GB | – | – | idle | 65 MB |
| Soak 20 VUs | 0.45 / 1.9 vCPU | 182 MB | 0.26 vCPU | 939 MB | idle | 80 MB |
| AI direct cold run | idle | – | – | – | **8.1 / 8.5 vCPU**, 507 MB | – |

php-fpm memory is proportional to busy children (32 × ~30 MB ⇒ ~1 GB worst case, observed 1.05 GB in the spike). The AI
service is the single biggest CPU consumer per request; it is idle during ordinary browsing.

## 15. Bottlenecks Identified

| # | Bottleneck | Evidence | Severity |
| --- | --- | --- | --- |
| B1 | **php-fpm `pm.max_children = 5`** (php:fpm image default) — every request beyond 5 queued in the FPM listen backlog | throughput flat at 78 req/s from 50 VUs; MySQL max 7 connections; app CPU ≤ 4.4 vCPU with 16 available; journey p50 5.3 s at 100 VUs | Critical for concurrency |
| B2 | Analytics cache data-version scanned `student_answers` (100 000 rows) via correlated sub-select on **every** cached request | `profile-analytics.php`: 76 ms of the 113 ms "cached" overview | High (hottest endpoint) |
| B3 | Analysis payload cached **per user** (`user:{id}:assessment:{id}`), 115 KB × users × assessments | Redis at 254.7 MB of 256 MB with 87 evictions during the 100-faculty ramp | High (cache thrash, evicts sessions) |
| B4 | AI service re-embedded every previous question on every similarity/analysis call | similarity vs 5 000 previous 8.85 s; full 200 q analysis 1.58 s; 8 vCPUs busy | High (CPU, latency) |
| B5 | `SELECT *` on `analysis_reports` with `ORDER BY … LIMIT` loads multi-MB findings JSON into the sort buffer | MySQL error 1038 "Out of sort memory" → HTTP 500 on `/api/analysis/history` and in `beginRun` after ~30 analyses existed | Critical (correctness under realistic data) |
| B6 | `throttle:api` (120/min/user) **not attached** — Laravel 12 requires `$middleware->throttleApi()` | 130 rapid requests → 130 × 200 | Medium (abuse protection) |
| B7 | Queue `retry_after 90 s` < job timeouts (180–1 800 s) → long jobs re-delivered while running; `block_for` unset | config review + queue test | Medium (duplicate work risk) |
| — | `GET /api/health` cache probe compared `int` strictly against predis string → always "degraded" | health JSON | Low (fixed) |

## 16. Optimizations Applied

No architecture change and no change to any academic computation. All changes are covered by tests.

| # | Change | Files |
| --- | --- | --- |
| B1 | Entrypoint renders a php-fpm pool from env: `PHP_FPM_MAX_CHILDREN` (32), `START_SERVERS` 8, `MIN_SPARE` 4, `MAX_SPARE` 16, `MAX_REQUESTS` 1000, `REQUEST_TIMEOUT` 180 s, `pm.status_path /fpm-status`; documented in `.env.production.example` | `backend/deploy/docker/entrypoint.sh`, `Dockerfile.prod` |
| B2 | Data-version now aggregates `student_submissions` (count, max updated_at, Σ awarded_marks, graded count) — no `student_answers` scan; `recalculateSubmission` `touch()`es the submission when marks are unchanged so answer edits still bump the version; `meta.data_version` exposed for verification | `AnalyticsScopeService`, `StudentSubmissionService`, `AcademicAnalyticsService`, `CacheCorrectnessTest` |
| B3 | `AnalysisPayloadCache` — one key per assessment (`assessment:{id}:analysis`, TTL 300 s), invalidated on analysis completion, failure, recommendation status change and feedback; collaborators share it, authorisation still enforced per request | `app/Services/AnalysisPayloadCache.php`, `AiAnalysisController`, `AnalyzeAssessmentJob`, `RecommendationFeedbackService`, `CacheCorrectnessTest` |
| B4 | `EmbeddingCache` (thread-safe LRU, 20 000 entries ≈ 30 MB, key = sha1(model+text)) inside `HuggingFaceService`; batch calls serve hits and embed only misses; `EMBEDDING_CACHE_SIZE` setting | `ai-service/app/services/huggingface_service.py`, `config.py`, `tests/test_embedding_cache.py` |
| B5 | `AnalysisReport::LIFECYCLE_COLUMNS` / `AnalysisHistoryService::LIST_COLUMNS` — lifecycle and list queries select narrow columns; regression test asserts no `SELECT *` under `ORDER BY` for this table | `AnalysisReport`, `AnalysisHistoryService`, `StudentPerformanceService`, `AssessmentBlueprintService`, `AnalyzeAssessmentJob`, `AnalysisReportWideColumnTest` |
| B6 | `$middleware->throttleApi()` in `bootstrap/app.php` | `ApiRateLimitTest` (121st request → 429, `X-RateLimit-Limit: 120`) |
| B7 | `retry_after` 1 900 s (env `REDIS_QUEUE_RETRY_AFTER`, `DB_QUEUE_RETRY_AFTER`), `block_for` 5 s; `ai-service/.dockerignore` so the image build no longer fails on the mounted HF cache | `config/queue.php`, `.env.production.example`, `ai-service/.dockerignore` |
| — | Health cache probe uses a random string token | `HealthController` |

## 17. Before/After Comparison

| Metric | before | after | change |
| --- | --- | --- | --- |
| Throughput at 100 VUs | 77.6 req/s | **139.7 req/s** | +80 % |
| Journey p50 @ 100 VUs | 5 279 ms | **490 ms** | −91 % |
| Journey p95 @ 100 VUs | 17 071 ms | 7 263 ms | −57 % |
| Journey p50 / p95 @ 50 VUs | 3 032 / 9 475 | **379 / 3 465** | −87 % / −63 % |
| Journey p50 / p95 @ 25 VUs | 1 454 / 5 211 | **331 / 2 430** | −77 % / −53 % |
| Analytics overview cached (1 VU) med | 113 ms | **38 ms** | −66 % |
| Analytics overview cold (1 VU) med | 625 ms | 537 ms | −14 % |
| Analytics course (1 VU) med | 32 ms | 22 ms | −31 % |
| Overview p95 @ 100 VUs | 8 176 ms | 2 905 ms | −64 % |
| Login p95 @ 100 VUs | 3 450 ms | 1 374 ms | −60 % |
| MySQL connections used @ 100 VUs | 7 (starved) | 33 | php-fpm no longer the ceiling |
| Redis memory, 100-faculty ramp | 254.7 MB, 87 evictions | 65 MB peak, 0 evictions | |
| AI similarity vs 5 000 previous (warm) | 8 851 ms | **175 ms** | −98 % |
| AI full analysis 200 q (warm) | 1 579 ms | **97 ms** | −94 % |
| AI full analysis 200 q (cold, first time) | 1 579 ms | ≈1 580 ms | unchanged (inference) |
| `/api/analysis/history` with many large analyses | HTTP 500 (MySQL 1038) | 200 | fixed |
| `throttle:api` | never triggers | 429 at request 121 | fixed |
| Errors in every load run | 0 | 0 | |

Login latency at 1 VU (158–161 ms) is unchanged by design — it is bcrypt cost.

## 18. Performance Regression Results

| Suite | result |
| --- | --- |
| Laravel `php artisan test` (incl. new `tests/Feature/Performance/*`: QueryCount 4 tests / 48 assertions, CacheCorrectness, ApiRateLimit, AnalysisReportWideColumn) | 504 passed |
| AI service `pytest` (incl. `test_embedding_cache.py`) + `ruff` | 227 passed, lint clean |
| Frontend `tsc --noEmit`, `vitest`, `vite build` | pass |
| **Academic correctness** — `snapshot-academic.sh` twice on unchanged data with the Redis cache flushed in between (`academic-after2` vs `academic-after3`, 23 files: current analyses 10/50/100/200 q, analysis history, analytics overview/course/outcomes/performance/similarity/AI, CO/PO, student performance, direct AI analysis) | `diff -rq` → **identical** |
| Direct AI `analyze-assessment` (20 q) before vs after the embedding cache | quality 44.67 / `REQUIRES_ATTENTION`, 5 recommendations, 0 duplicates — identical except random `rec_*` ids |
| Timeout behaviour (`timeout-test.sh`): AI container paused 45 s | accept 58 ms (202); status stayed `processing`, no error; completed 3 s after unpause |
| Timeout behaviour: AI container paused 130 s (> `AI_SERVICE_TIMEOUT` 120 s) | cURL error 28 at exactly 120 s → status `failed` with message; job retried after the 30 s backoff and **completed** (analysis 337, version 8, `is_current`), total 155 s; API stayed responsive throughout |
| Rate limits (`rate-limits.js`) | login 429 at request 21 (20/min/IP); api 429 at 121; ai-analysis 429 at 31; uploads 429 at 26; a second user on another IP unaffected (200) |

The `academic-before` vs `academic-after` snapshot pair is **not** a valid equality check and is kept only for
transparency: between the two, the AI load scenarios created new analysis versions and LO alignments on the same
assessments, so the analytics/analysis files legitimately differ (e.g. `lo_alignment_score 65 → 100`,
`learning_outcome_id null → 58`). The controlled `after2/after3` pair is the evidence for §40 of the spec.

## 19. Known Limitations

1. **Single host** — load generator, browser and all services share 16 vCPUs; production numbers will differ. Relative
   before/after figures are reliable; absolute p95 at 100 VUs is pessimistic.
2. **Cold analytics overview** is 537 ms at 1 VU and the main contributor to p95 under concurrency (80 aggregate
   statements, cached 5 min). Further reduction would need materialised aggregates — an architecture change, out of
   scope; recorded as `NEEDS_OPTIMIZATION` for ≥ 50 simultaneous cold dashboards.
3. **`GET /api/courses` is unpaginated** (370 KB for 1 000 courses, 86 ms). Realistic faculty own tens of courses; not
   changed because the frontend consumes the whole list. Flagged for pagination if institution-wide course lists are
   introduced.
4. **Analysis payload (~840 KB for 200 questions)** and the assessment page (6 605 DOM nodes) are large but render in
   < 250 ms API wait; virtualisation not attempted.
5. **Single queue worker** — AI analyses are serialised (20 concurrent ⇒ 12 s for the last). Scale with more
   `queue:work` replicas (CPU permitting: each analysis saturates the AI service's cores for ~1–1.6 s cold).
6. **Single uvicorn worker** — torch already uses all cores; adding workers would multiply model memory (~450 MB each)
   without more CPU. Not changed.
7. **Question generation, rubric generation and document upload/parsing** were not load-tested: no generation model is
   configured in the perf environment and there is no representative document corpus. Marked `NOT_TESTED`.
8. **Horizon** is not installed; queue metrics come from Redis `LLEN`, worker logs and end-to-end timings.
9. **Main JS chunk 630 KB (159 KB gzip)** exceeds the common 500 KB warning; route chunks are split; no change made.
10. The 50-job queue run's wall time (61 s) includes 429 back-off on status polls caused by the `ai-analysis` throttle;
    per-job processing times are accurate, the aggregate is an upper bound.

## 20. Recommended Production Capacity

Measured on 16 vCPU / 7.3 GB shared with the load generator; recommendations are stated in those terms.

| Deployment | recommendation (measured basis) |
| --- | --- |
| **App container** | `PHP_FPM_MAX_CHILDREN=32` for ≥ 8 vCPU / 2 GB (32 × ~30 MB peak = ~1 GB observed). For 4 vCPU hosts use 16. |
| **Concurrent faculty (interactive browsing)** | **Up to 50 simultaneously active faculty** with median < 0.4 s and p95 < 3.5 s on this class of host (0 errors); 100 simultaneous is served without errors but with p95 ≈ 7 s during cold-dashboard bursts. Given typical think times this corresponds to several hundred logged-in users. |
| **AI analyses** | ~1–1.6 s of full-CPU inference per cold analysis (10–200 q vs 500 previous); warm (cached embeddings) 50–100 ms. One worker sustains ≈ 240 small analyses/min; plan one `queue:work` replica per ~10 simultaneous analysis requesters if < 10 s completion is required, and give the AI service ≥ 8 vCPU. |
| **MySQL** | 512 MB buffer pool held the 100 k-answer dataset entirely in memory (> 99.99 % hit ratio); ≤ 35 connections at 100 VUs — the default `max_connections 200` is ample. |
| **Redis** | 256 MB `allkeys-lru` is sufficient after B3 (peak 80 MB in 30 min soak with 20 users, 65 MB at 100 VUs). |
| **Rate limits** | Keep as configured; they engaged exactly at the configured counts and did not affect other users. |

## 21. Final Status

| Subsystem | classification | basis |
| --- | --- | --- |
| Authentication | **ACCEPTABLE** | login med 158 ms / p95 165 ms (1 VU), p95 750 ms @ 50 VU, 1.37 s @ 100 VU; bcrypt-bound; limits work |
| Course API | **ACCEPTABLE** | 13–18 ms med; 1 000-course list 86 ms but unpaginated (§19.3) |
| Assessment API | **EXCELLENT** | 17–39 ms med for 10–200 q, flat query counts, p95 237 ms @ 50 VU |
| Analytics | **ACCEPTABLE** (cached) / **NEEDS_OPTIMIZATION** (cold at ≥ 50 concurrent) | 38 ms warm, 537 ms cold, p95 1.5 s @ 50 VU, 2.9 s @ 100 VU |
| AI similarity / analysis service | **ACCEPTABLE** | cold 0.9–1.6 s CPU inference, warm 46–97 ms; async via queue, 0 failures, timeout + retry verified |
| Question generation | **NOT_TESTED** | no generation model in environment |
| Rubric generation | **NOT_TESTED** | same |
| Document processing | **NOT_TESTED** | no corpus / not load-tested |
| Reports (PDF/CSV/XLSX) | **ACCEPTABLE** | create med 48–56 ms, p95 0.26–0.8 s (sync, ~900 KB PDF), download 15–28 ms; 1 405 reports in soak with p95 47 ms |
| Queue (queue:work, no Horizon) | **ACCEPTABLE** | 240 small jobs/min single worker, idempotent, retry_after fixed; serialised under burst (§19.5) |
| Redis | **EXCELLENT** | 0 evictions after B3, ≤ 80 MB, < 8 % CPU |
| Database (MySQL) | **EXCELLENT** | 0 slow queries, 0 disk tmp tables, > 99.99 % buffer hit, indexed access on all hot paths; 1038 error fixed |
| Frontend | **ACCEPTABLE** | API wait 68–221 ms per page, no duplicate requests, 0 5xx; main chunk 630 KB (159 KB gzip) |
| Stability (spike / soak) | **EXCELLENT** | 0 errors in 10 748-request spike and 59 370-request 30-min soak; +2 MB app memory drift |

**Overall: ACCEPTABLE — production-ready for the measured workload.** Seven real bottlenecks were found by measurement
and fixed (one of them, B5, was a hard failure on realistic data volumes; B1 was the concurrency ceiling); academic
outputs are unchanged (controlled snapshot pair identical); no critical bottleneck remains unexplained. Open items are
listed in §19 and tracked as follow-ups rather than blockers.
