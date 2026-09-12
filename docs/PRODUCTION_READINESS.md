# FacultyLens — Production Readiness Report

**Date:** 2026-09-11 · **Branch:** `feature/final-integration-production` · **Scope:** STEPS 01–40

This report records what was verified, how, and with which results. It does not claim the system is “100 % secure”
or that the AI is “accurate”; it lists evidence, known limitations and the remaining go-live actions.

---

## 1. System architecture (as deployed)

```
Browser ── HTTPS ──▶ nginx (frontend image: static React, TLS, security headers, rate limits)
                        │ /api, /sanctum → fastcgi
                        ▼
                     Laravel API (php-fpm)  ──▶ MySQL 8 (internal network only)
                        │  auth · authz · business logic · audit   ──▶ Redis 7 (cache + queue, password)
                        │                                          ──▶ FastAPI AI service (internal only, X-AI-Service-Key)
                     worker (queue:work redis)   scheduler (schedule:work)      └▶ Hugging Face models (loaded once at startup)
```

Chain verified end-to-end with real data: Faculty → Course → CO/PO → Documents → Assessment → Blueprint → Version →
Questions → AI Analysis (question/alignment/similarity/quality/recommendations) → Rubric → Submission → Answers →
AI grading assistance → Faculty grade → Performance → Learning gaps → Analytics → Institutional report (PDF/CSV/XLSX).

Files: `docker-compose.prod.yml`, `backend/Dockerfile.prod`, `frontend/Dockerfile`, `deploy/nginx/facultylens.conf`,
`backend/deploy/docker/entrypoint.sh`. Development stack remains `docker-compose.yml`.

## 2. Testing status (actual results)

| Suite | Command | Result |
|---|---|---|
| Backend (PHPUnit, sqlite) | `php artisan test` | **446 passed**, 3530 assertions (incl. `ProductionReadinessTest` 9 tests, `InstitutionalReportTest` 21, `SecurityAcademicIntegrityTest` 9) |
| Frontend (Vitest) | `npm test` | **253 passed** (20 files) |
| Frontend build / types | `npm run build`, `tsc --noEmit` (strict) | build OK, 0 type errors |
| AI service (pytest) | `pytest -q` | **196 passed** |
| AI lint | `ruff check .` (E, F, B, S, W) | **All checks passed** (25 unused imports removed, 2 silent `except: pass` now logged) |
| PHP style | `vendor/bin/pint` on STEP 39/40 files | formatted |
| End-to-end (Docker stack, real AI) | `bash backend/tests/e2e_all.sh` | **15/15 passed** (cors, login validation, submissions, blueprint, versioning, alignment, rubric, grading, performance/CO-PO, chat, question generation, collaboration, AI evaluation, analytics, institutional reports) — ~17 min |
| Integrity command | `php artisan facultylens:integrity-check` | 0 issues on seeded data; detects injected corruption (tested) |
| Migrations on clean DB + seed | `RefreshDatabase` + `DatabaseSeeder` in tests; restore test `migrate:status` | OK, 0 pending |

Regression: every previous feature suite (STEPS 01–39) is part of the 446 backend / 253 frontend / 196 AI tests above.

## 3. Security status

| Area | Status | Evidence |
|---|---|---|
| Authentication | ✅ | Sanctum cookie sessions; register/login/logout/current-user/CSRF/expired-session covered by `AuthTest`, `SecurityAcademicIntegrityTest`, E2E `e2e_login_validation.sh`; `throttle:auth` 20/min |
| Authorization / RBAC | ✅ | STEP 34 matrix (`OWNER/EDITOR/REVIEWER/VIEWER`) + `FACULTY/ADMIN`; `ProductionReadinessTest::test_faculty_b_cannot_read_or_mutate_any_faculty_a_resource_by_id` sweeps 19 GET + 16 mutating endpoints across courses, outcomes, assessments, questions, blueprints, versions, submissions, answers, rubrics, reports, analytics, AI analysis → 403/404, data unchanged; reviewer test confirms read-only + no student data |
| Course isolation | ✅ | E2E cross-faculty sections in analytics/reports/versioning/collaboration scripts; unit tests above |
| Document security | ✅ | private `local` disk, policy-checked `download`, no `Storage::url()`/public links anywhere; `test_document_download_security` |
| Student privacy | ✅ | reports/analytics aggregate only; `InstitutionalReportTest` asserts no names/identifiers/e-mails/answers in exports; E2E leak check = 0 |
| Academic integrity | ✅ | AI never writes `awarded_marks`, approvals, mappings, rubrics (`AiGradingResult.suggested_marks` separate; version finalize/approve only via faculty endpoints; `test_finalized_version_grade_and_analysis_invariants_hold`) |
| AI service security | ✅ | internal network only in prod compose; `X-AI-Service-Key` required (401 otherwise — pytest `test_protected_endpoints_reject_missing_service_key`); `DEBUG=false` disables docs; key never sent to browser |
| AI failure handling / timeouts | ✅ | `AI_SERVICE_CONNECT_TIMEOUT=10`, `AI_SERVICE_TIMEOUT=120`; 503 “AI analysis is temporarily unavailable” paths tested in `AiAnalysis*`/`Live*` suites with `Http::fake`; readiness degrades (not fails) when AI is down |
| Prompt-injection / data boundaries | ✅ reviewed | RAG retrieval limited to caller's course documents (`AcademicChatService`, E2E chat isolation); document text passed as context only; system instructions server-side |
| Input validation | ✅ | FormRequests on every write route (types, ranges, enums, FKs, dates, file MIME/extension/size ≤ 20 MB); `$fillable` on all models; Eloquent/prepared statements only |
| Rate limiting | ✅ | `api` 120/min, `auth` 20/min, `ai-analysis` 30/min, `uploads` 25/min, chat, generation, collaboration; reports preview/create share `ai-analysis`; nginx adds 30 r/s (API) and 5 r/s (auth) per IP |
| Transport & cookies | ✅ config | HTTPS-only nginx (80→301), TLS 1.2/1.3, HSTS; `SESSION_SECURE_COOKIE` defaults to true in production, `HttpOnly`, `SameSite=lax`; `TRUSTED_PROXIES` |
| CORS / CSRF | ✅ | explicit `CORS_ALLOWED_ORIGINS`; localhost patterns only outside production; no wildcard with credentials; CSRF on every mutation (E2E helpers exercise it) |
| Security headers | ✅ | Laravel: nosniff, X-Frame-Options, Referrer-Policy, Permissions-Policy, CSP `default-src 'none'` on API, HSTS on HTTPS; nginx: SPA CSP (no `unsafe-eval`), HSTS |
| Observability | ✅ | `RequestLoggingMiddleware`: request id (`X-Request-Id`), user id, route, status, duration; no bodies/tokens/cookies; audit events across auth, courses, assessments, blueprints, versions, analysis, recommendations, rubrics, grading, reports, exports, collaboration |
| Secrets | ✅ | `.env*` ignored, only `*.example` tracked; production templates contain `CHANGE_ME` placeholders; `APP_DEBUG=false`; `backend/.env.testing` holds development-only values (root123 for the local Docker MySQL) |
| Dependency audits | ⚠️ | `composer audit`: **0 advisories**. `pip-audit`: **0 known vulnerabilities** in `requirements.txt` (pip itself flagged — tooling, not shipped). `npm audit`: **2 moderate** in `react-router`/`react-router-dom` 6.x (open redirect via backslash in `<Link>`, SSR hydration constructor injection — SSR not used). Fix requires major upgrade to 7.x; scheduled, see §8 |
| Static analysis | ✅ / ⚠️ | TypeScript strict, ruff clean, Pint on new code; no PHPStan/ESLint configured (see §8) |

## 4. Data integrity status

| Invariant | Evidence |
|---|---|
| Assessment versions immutable once finalized; live edits never touch snapshots | `AssessmentVersionTest`, `ProductionReadinessTest` (409 on PUT, marks unchanged), E2E versioning |
| Blueprint validation single source (STEP 37) | `AssessmentBlueprintTest`; reports/analytics call `AssessmentBlueprintService` |
| Historical analysis attached to version; stale detection | `AnalysisHistory*` tests, E2E versioning “STALE” checks |
| Finalized faculty grades authoritative; AI suggestions separate | `AiGradingTest`, `StudentPerformanceTest`, readiness invariants test |
| Inter-grader: labelled “FacultyLens Agreement Indicator”, reported unavailable (no multi-grader tables) | `InterGraderReportBuilder`, analytics |
| Reports bound to `assessment_version_id`, immutable files | `InstitutionalReportTest::test_version_report_stays_bound…`, E2E (v1 report says 100 marks after v2 = 50) |
| No orphans / dangling FKs | `facultylens:integrity-check` (38 checks, read-only); restore test orphan counts 0 |
| Transactions on multi-step writes | 48 `DB::transaction` blocks across services (versions, blueprints, grading, rubrics, submissions, generation, evaluation, chat, analysis) |

## 5. Performance status

**Updated by STEP 43** — a full performance and load test was run against the production topology (nginx → php-fpm +
opcache → MySQL/Redis, `queue:work`, FastAPI AI service) with a 100 k-answer dataset; see
[PERFORMANCE_TEST_REPORT.md](PERFORMANCE_TEST_REPORT.md) and [PERFORMANCE_TEST_ENVIRONMENT.md](PERFORMANCE_TEST_ENVIRONMENT.md).
Headline numbers (Docker Desktop, 16 vCPU shared with the load generator): 0 errors in every run; 100 concurrent
faculty at 139.7 req/s with journey p50 490 ms; 50 concurrent with p95 3.5 s; 30-min soak at 20 VUs with +2 MB app
memory drift; async AI analysis of a 200-question paper completes in 4–12 s end-to-end. Seven bottlenecks were fixed
(php-fpm `pm.max_children`, analytics data-version scan, per-user analysis cache, AI embedding cache, MySQL 1038 on
`SELECT *` of analysis reports, unattached `throttle:api`, queue `retry_after`). Academic outputs verified identical.

The table below is the earlier dev-stack smoke test and is kept for history only.

Smoke test only (sequential, single client, `scripts/perf-smoke.sh`, development stack: `php artisan serve` inside Docker
Desktop on Windows with a bind-mounted code tree, **no opcache**). p50 values are representative of application work;
p95/max spikes of 1.6–6 s occurred on *every* endpoint including `/up` and are caused by the dev server + bind-mount
file I/O, not by specific queries (verified: `runChecks()` = 22 ms warm inside the container).

| Endpoint | p50 | p95 |
|---|---|---|
| `/api/health` | 89 ms | 1.6 s* |
| `/api/auth/user` | 91 ms | 6.3 s* |
| `/api/courses` | 91 ms | 1.6 s* |
| `/api/assessments` | 258 ms | 1.6 s* |
| `/api/analytics/overview` (cached) | 409 ms | 1.7 s* |
| `/api/analytics/overview?fresh=1` | 712 ms | 1.7 s* |
| `/api/reports/types` · `/filters` · `/reports` | 153–207 ms | 1.6–2.6 s* |
| `/api/health/ready` (5 probes, 5 s shared cache) | 5.7 s* | — |

\* dev-stack artifact (see above). **A load test on a production-like Linux host (php-fpm + opcache, Redis, no bind
mounts) has not been performed yet and is a go-live prerequisite** (§8). Dataset sizes tested: 25 users, 11 courses,
110 questions, 24 submissions, 48 reports; E2E AI analysis of a 10-question paper completes in 30–60 s.

Design measures in place: analytics aggregated in SQL with a user-scoped cache key + data-version fingerprint;
pagination on list endpoints; large/institution-wide reports queued; PDF tables capped (`pdf_row_limit`); embeddings
cached per document chunk; queue jobs bounded (`--tries=2 --backoff=30 --timeout=900 --memory=512`).

## 6. Backup & recovery status

| Check | Result (2026-09-11, development stack) |
|---|---|
| `scripts/backup.sh` | `db.sql.gz` 68 KB, `storage.tar.gz` 9.3 MB, `manifest.json` with counts + SHA-256 |
| `scripts/restore.sh <backup>` (scratch DB) | checksum OK · 72 tables · users 25 / courses 11 / assessments 11 / questions 110 / versions 17 / submissions 24 / answers 24 / reports 48 / audit 526 — **identical to manifest** · orphans 0 · pending migrations 0 · scratch dropped, live DB untouched |
| DR parameters | RPO ≤ 24 h (daily), RTO ~1 h — to be confirmed by the institution (`docs/DEPLOYMENT.md` §6) |
| Retention | documented in `docs/DEPLOYMENT.md` §7 (academic records never auto-deleted; report files 7 days; audit retained) |

## 7. Monitoring & deployment status

- Liveness `/api/health`, readiness `/api/health/ready` (database, cache, storage, queue, AI; coarse, secret-free; admin `?details=1`), AI `/health` + `/ready` (503 until model loaded). Compose healthchecks on every production service.
- Structured request logs (`api.request`) with request ids; JSON log driver with rotation; worker healthcheck via `queue:monitor`.
- Production images built and inspected: backend `php:8.2-fpm-alpine` with `pdo_mysql zip gd intl opcache pcntl`, `display_errors=Off`, `expose_php=Off`, no dev dependencies, no tests, runs as `www-data`; frontend multi-stage Node → nginx; `nginx -t` passes on the production config; `docker compose -f docker-compose.prod.yml config` valid.
- Deployment, smoke test, rollback (application/config/queue/database), Horizon option: `docs/DEPLOYMENT.md`.
- Seeding: `DatabaseSeeder` refuses to run in production (`ALLOW_DEV_SEED` guard, tested); `DevelopmentSeeder` produces the full synthetic chain (faculty → … → finalized grades → performance run → report) with clearly fake identifiers (`DEV-STU-001`).

## 8. Known limitations & open issues

| # | Item | Severity | Plan |
|---|---|---|---|
| 1 | `react-router-dom` 6.x has 2 moderate advisories; fix is a major upgrade (7.x) | medium | upgrade in a dedicated branch with full frontend test run; open-redirect vector requires attacker-controlled `<Link to>` values, which the app does not render from user input |
| 2 | Load test performed on the prod compose topology locally (STEP 43, k6, 1–100 concurrent faculty, spike, 30-min soak — `docs/PERFORMANCE_TEST_REPORT.md`); not yet repeated on the actual production host | medium | re-run `performance/capacity.sh` and the soak profile on the staging/production host after deployment; set `PHP_FPM_MAX_CHILDREN` per §20 of the report |
| 3 | Laravel Horizon not installed; Redis workers use `queue:work` | low | optional add-on documented; `pcntl` already in the image |
| 4 | STEP 29 inter-grader statistics are reported as “not available” (no multi-grader data model) | low | feature backlog; indicator terminology already in place |
| 5 | No PHPStan/Larastan or ESLint configuration | low | add in CI; TypeScript strict and Pint cover the current baseline |
| 6 | Production TLS certificates, SMTP, DNS, `TRUSTED_PROXIES`, DB/Redis passwords must be provisioned by the operator | required | `.env.production.example`, `.env.prod.example` placeholders (`CHANGE_ME`) |
| 7 | AI service CORS is `*` (harmless: it is never browser-reachable in prod) | info | tighten if the service is ever exposed |
| 8 | `backend/.env.testing` (tracked) contains a development APP_KEY and the local Docker root password | info | development-only values; rotate if the file is ever reused |
| 9 | HF model download happens on first AI container start (`ai_models_cache` volume) | info | pre-warm the volume or bake the model into the image for air-gapped hosts |

## 9. Go-live checklist

- [x] STEPS 01–39 integrated; full E2E chain passes (15/15) on real data
- [x] Authentication, authorization, RBAC, course isolation, document security, student privacy verified by tests
- [x] AI service internal-only with service key; timeouts; safe failure messages; readiness gate on model load
- [x] Input validation, file upload limits, rate limiting, security headers, CORS/CSRF configuration
- [x] Assessment version immutability, blueprint/analysis/grading/report integrity tests
- [x] Health (liveness/readiness), structured request logging, audit logging
- [x] Backup created and restore tested; DR/retention documented
- [x] Dependency audits run (composer 0, pip 0, npm 2 moderate — tracked); static analysis (tsc, ruff, pint)
- [x] Production Docker images build; nginx config validates; prod compose validates
- [x] README, `docs/API.md`, `docs/DEPLOYMENT.md`, this report
- [ ] Provision production secrets, TLS, SMTP, DNS; set `CORS_ALLOWED_ORIGINS`/`SANCTUM_STATEFUL_DOMAINS` to the real domain
- [ ] Deploy to a staging host with `docker-compose.prod.yml`; run smoke test (§4 of DEPLOYMENT.md) and re-run the STEP 43 load scenarios there (§8 #2)
- [ ] Schedule daily `scripts/backup.sh` + off-host copy; nightly `facultylens:integrity-check`
- [ ] Wire alerts (5xx rate, readiness, failed jobs, AI readiness, disk) into institutional monitoring
- [ ] Resolve or formally accept `react-router` advisories

**Definition of done reached for engineering scope:** real data → secure API → validated business logic → AI assistance
→ faculty review → immutable academic record → analytics → authorized institutional reporting works end-to-end and is
covered by automated tests. Remaining items are operational (secrets, hosting, load test, monitoring integration).
