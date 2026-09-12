# FacultyLens E2E Validation Report

STEP 41 — End-to-End Testing & System Validation. Branch `feature/e2e-system-validation`.
Every number below comes from a real run on the date stated; nothing is estimated. Logs are kept under
`backend/storage/logs/e2e/` (git-ignored) and Playwright artefacts under `frontend/playwright-report/`, `frontend/test-results/`.

Status vocabulary: **PASS** · **FAIL** · **BLOCKED** (could not be executed in this environment) · **NOT_APPLICABLE** (feature does not exist in FacultyLens as built).

---

## 1. Test Environment

| Item | Value |
|---|---|
| Host | Windows 11, Git Bash (MINGW64), Docker Desktop |
| Docker dev stack (`docker-compose.yml`) | `app` (Laravel, `php artisan serve` :8080), `mysql` 8 (:3307), `ai-service` (FastAPI :8001, embeddings model loaded), `queue-worker` (database driver), `phpmyadmin` (:8081) |
| Clean-start stack | `scripts/docker-compose.clean-test.yml` — isolated project `facultylens-clean`, fresh volume, ports 8090 / 3308 / 8181 / 8011 |
| Backend unit/feature tests | PHPUnit 11, sqlite in-memory (`phpunit.xml`); live-AI tests call the running ai-service on :8001 and `skip` when it is down |
| Frontend tests | Vitest 3 + React Testing Library (jsdom); Playwright 1.63 (Chromium, 1 worker, no retries) + `@axe-core/playwright` 4.13 |
| AI service tests | pytest (project `.venv`, Python 3.12), ruff |
| Browser E2E target | production build served by `vite preview` on `http://127.0.0.1:4173`, API on `http://127.0.0.1:8080/api` (real MySQL, real AI service) |
| Test data | Created by the tests themselves with unique e-mails / codes (`golden.<ts>@…`, `journey1.<ts>…`, `CSE-401`, `E2E-…`). No production data. Feature tests use `RefreshDatabase`; Docker-based scripts leave their own rows behind but never touch other users' data. |

## 2. Application Components

| Component | Validated through |
|---|---|
| Authentication (Sanctum cookie session, CSRF) | `AuthTest`, `SecurityTest`, Golden Path, Playwright Journey 1, clean-start API journey |
| Courses / Learning Outcomes / Programs / CO-PO mapping | `CourseManagementTest`, `CoPoMappingTest`, `GoldenPathTest`, Journey 2 |
| Assessments / questions / documents | `AssessmentManagementTest`, `DocumentProcessingTest`, `DocumentProcessingResilienceTest` |
| AI service health / question analysis / LO alignment / similarity / unified analysis | `AiIntegrationTest`, `AiQuestionAnalysisTest`, `LearningOutcomeAlignmentTest`, `SemanticSimilarityTest`, `UnifiedAiAnalysisApiTest`, `AiAnalysisTest`, `LivePipelineEndToEndTest`, ai-service pytest (196) |
| Quality engine / recommendations / feedback | `AssessmentQualityEngineTest`, `AssessmentQualityScoreNormalizationTest`, `RecommendationEngineTest`, `RecommendationFeedbackTest`, `RecommendationTraceabilityTest`, Journeys 3–4 |
| Rubrics / question generation / blueprints / versions | `RubricTest`, `RubricAlignmentTest`, `QuestionGenerationTest`, `AssessmentBlueprintTest`, `AssessmentVersionTest`, Journeys 5–6 |
| Submissions / grading / performance | `StudentSubmissionTest`, `AiGradingTest`, `StudentPerformanceTest`, Journey 7 |
| Analytics / institutional reports / privacy | `AcademicAnalyticsTest`, `InstitutionalReportTest`, `AssessmentReportTest`, Journey 8 |
| Collaboration, academic chat, AI evaluation | `CollaborationTest`, `AcademicChatTest`, `AiEvaluationTest` |
| Security / integrity / infrastructure | `SecurityTest`, `SecurityAcademicIntegrityTest`, `CrossFacultySecurityE2ETest`, `DatabaseIntegrityTest`, `DatabaseSchemaIntegrityTest`, `AiFailureAndQueueTest`, `ProductionReadinessTest`, clean-start test |

## 3. Backend Tests

Command: `cd backend && php artisan test` (ai-service on :8001 running, so live tests executed rather than skipped).

| Result | Value |
|---|---|
| Tests | **464 passed, 0 failed** (3 939 assertions) |
| Feature test classes | 44 (all green) |
| New in STEP 41 | `GoldenPathTest` (1 test, 179 assertions, live AI), `DatabaseIntegrityTest` (9), `AiFailureAndQueueTest` (8) |

Spec sections → status:

| # | Area | Status | Evidence |
|---|---|---|---|
| 5 | Authentication | PASS | `AuthTest`, `SecurityTest` (register/login/logout/session/CSRF/rate-limit), Golden Path |
| 6 | Course workflow | PASS | `CourseManagementTest`, Golden Path (create → LOs → link program) |
| 7 | Learning outcomes | PASS | `CourseManagementTest`, Golden Path (3 LOs, Bloom levels) |
| 8 | CO/PO mapping | PASS | `CoPoMappingTest`, Golden Path (mappings + `analyze`); reference guard in `DatabaseIntegrityTest` |
| 9 | Assessment workflow | PASS | `AssessmentManagementTest`, Golden Path (100 marks / 120 min, 8 questions) |
| 10 | Document processing (valid PDF/DOCX, invalid type, oversized, corrupt, extraction failure) | PASS | `DocumentProcessingTest`, `DocumentProcessingResilienceTest` |
| 11 | AI service health (healthy + unavailable) | PASS | `AiIntegrationTest`, `AiFailureAndQueueTest::readiness reports degraded` |
| 12 | Question analysis | PASS | `AiQuestionAnalysisTest`, `LivePipelineEndToEndTest` |
| 13 | LO alignment | PASS | `LearningOutcomeAlignmentTest` |
| 14 | Similarity | PASS | `SemanticSimilarityTest` (duplicate / near-duplicate / distinct) |
| 15 | Complete AI analysis (persisted, historical, faculty override) | PASS | `UnifiedAiAnalysisApiTest`, `AnalysisHistoryTest`, `AiAnalysisTest` |
| 16 | AI failure handling | PASS | `AiFailureAndQueueTest`: 503 → 502 with safe message and `FAILED` report; connection refused → 502; timeout → 504; recovery after outage; no stack traces in responses |
| 17 | Quality engine | PASS | `AssessmentQualityEngineTest`, `AssessmentQualityScoreNormalizationTest` |
| 18 | Recommendation engine (assistive, faculty accept/reject) | PASS | `RecommendationEngineTest`, `RecommendationFeedbackTest`, `RecommendationTraceabilityTest` |
| 19 | Rubric (draft → approve, alignment) | PASS | `RubricTest`, `RubricAlignmentTest`, Golden Path |
| 20 | Question generation (drafts until approved) | PASS | `QuestionGenerationTest`; live generation in `e2e_golden_path.sh` |
| 21 | Blueprint (create/validate/finalize/validate-questions) | PASS | `AssessmentBlueprintTest`, Golden Path |
| 22 | Versioning (finalized version immutable, snapshot survives) | PASS | `AssessmentVersionTest`, `DatabaseIntegrityTest` (PUT on finalized → 409; snapshot intact after question edit) |
| 23 | Student submission (create, answers, CSV import, state machine) | PASS | `StudentSubmissionTest`, `DatabaseIntegrityTest` (invalid transitions rejected, cross-assessment answer 422) |
| 24 | Faculty grading (AI suggestion never final; finalize-grade) | PASS | `AiGradingTest`, Golden Path (48 answers finalized by faculty) |
| 25 | Inter-grader consistency | NOT_APPLICABLE | FacultyLens has a single grader per submission (owner or collaborator with grading permission); there is no multi-grader / second-marker data model to compare agreement against. Rubric-based consistency across *questions* is covered by `RubricAlignmentTest`. |
| 26 | Student performance | PASS | `StudentPerformanceTest`, Golden Path (48 finalized answers analysed, average cross-checked) |
| 27 | Learning gaps | PASS | `StudentPerformanceTest` (gap detection thresholds), Golden Path performance payload |
| 28 | Academic analytics | PASS | `AcademicAnalyticsTest`, Golden Path `analytics/overview` |
| 29 | Real-data reactivity (no hard-coded numbers) | PASS | `AcademicAnalyticsTest` asserts overview changes after new grades; Journey 8 asserts KPI values match API |
| 30 | Reporting (PDF / CSV / XLSX) | PASS | `InstitutionalReportTest`, Golden Path downloads ASSESSMENT_QUALITY (PDF), QUESTION_ANALYSIS (CSV), STUDENT_PERFORMANCE (XLSX), CO_COVERAGE (CSV), PO_COVERAGE (CSV) and checks magic bytes |
| 31 | Report privacy | PASS | Golden Path: generated report bytes contain no student identifiers (`GP-STU-001`); `InstitutionalReportTest` scope rules |
| 32 | Security (authZ, IDOR, CSRF, rate limits, mass assignment) | PASS | `SecurityTest`, `SecurityAcademicIntegrityTest`, `CrossFacultySecurityE2ETest`, Golden Path stranger checks (403/404) |
| 33 | Database integrity | PASS | see §6 |
| 34 | Redis / Horizon | see §8 | |

## 4. Frontend Tests

| Suite | Command | Result |
|---|---|---|
| Type check | `npx tsc --noEmit -p tsconfig.json` | PASS (0 errors) |
| Unit / component (Vitest + RTL) | `npm run test` | **261 passed, 0 failed** (21 files) — includes new `apiErrorMapping.test.ts` (8) |
| Production build | `npm run build` | PASS (chunk-size warning only) |
| E2E type check | `npx tsc -p tsconfig.e2e.json` | PASS |
| Playwright journeys | `npm run test:e2e` | see §9 |

## 5. AI Service Tests

| Suite | Command | Result |
|---|---|---|
| pytest | `.venv/Scripts/python.exe -m pytest -q` | **196 passed, 0 failed** (29 deprecation warnings from third-party libs) |
| ruff | `ruff check app tests` | PASS ("All checks passed!") |
| Coverage areas | health/ready, preprocess, question analysis, alignment, similarity, retrieval, quality engine, recommendation engine, rubric generator/alignment, question generator, grading engine, performance, assessment analysis, evaluation inventory, academic chat, security, threshold boundaries, prompt builder | |

## 6. Database Tests

`DatabaseIntegrityTest` (9 tests) + `DatabaseSchemaIntegrityTest` + `php artisan facultylens:integrity-check`.

| Check | Status |
|---|---|
| Foreign keys enforced (orphan question / answer rejected) | PASS |
| Cascade on course delete leaves no orphans; integrity-check exits 0 | PASS |
| Unique constraints (user e-mail, course code per faculty, student per submission) | PASS |
| Answer must belong to a question of the same assessment (422) | PASS |
| Blueprint / version / CO-PO reference guards | PASS |
| Marks bounds (≤ question max, ≥ 0), structural numbers > 0 | PASS |
| Submission state machine incl. new GRADED guard (unreviewed answers → 422) | PASS |
| Finalized version immutable, snapshot survives later edits | PASS |
| Fresh seed integrity (clean-start stack) | PASS — 0 issues |
| Dev database integrity | 2 pre-existing issues (submissions 275/277 marked GRADED with an unreviewed answer, created before the guard existed; version 27 total mismatch) — recorded, not modified (dev data). Golden Path asserts it introduces **no new** issues (before = after = 2). |

## 7. Security Tests

| Check | Status | Evidence |
|---|---|---|
| Unauthenticated access → 401 / redirect | PASS | `SecurityTest`, Journey 1 |
| Cross-faculty resource access (IDOR) → 403/404 | PASS | `CrossFacultySecurityE2ETest`, Golden Path stranger checks on course, assessment, report |
| CSRF required on mutations | PASS | `SecurityTest`, clean-start API journey (token rotates after login) |
| Rate limiting (login, uploads) | PASS | `SecurityTest`; `throttle:uploads` observed (25/min) during Golden Path design |
| Mass-assignment / validation | PASS | `SecurityTest`, `DatabaseIntegrityTest` |
| No stack traces / SQL in API errors | PASS | `AiFailureAndQueueTest`; frontend `friendlyStatusMessage` replaces any leaked 5xx body containing `exception`, `SQLSTATE`, `.php` |
| Student privacy in reports and UI | PASS | Golden Path, `InstitutionalReportTest`, Journey 7 (privacy notice) |
| Dependency audits | see §14 | |

## 8. Docker/Infrastructure Tests

| Check | Status | Evidence |
|---|---|---|
| Clean Docker start from zero (`scripts/clean-start-test.sh`) | PASS | isolated project, fresh MySQL volume: 72 tables created, 0 pending migrations, seed OK, `/api/health` ok, `/api/health/ready` ready (database/cache/storage/queue/ai_service all `ok`), AI `/ready model_loaded=true`, register 201 → logout → login → `/auth/user` 200, seeded faculty login + courses visible, queue worker running, integrity issues 0, failed jobs 0, 0 error lines in logs after migration. ≈ 95 s. |
| Queue (database driver) | PASS | `AiFailureAndQueueTest`: job retried after AI failure then succeeds; exhausted tries → `failed_jobs` + "maximum retry attempts" message; every Job declares `tries`, `timeout`, `failed()`; sync vs async queue |
| Redis / Horizon | NOT_APPLICABLE (dev) / BLOCKED (prod) | Development and test stacks use the **database** cache/session/queue drivers by design; Redis is only used by `docker-compose.prod.yml` and Horizon is an optional add-on documented in `docs/DEPLOYMENT.md`. No Redis instance exists in the dev environment, so Redis-backed queue processing was not executed here. `predis` is installed and configuration is validated by `ProductionReadinessTest`. |
| Health / readiness endpoints | PASS | clean-start test, `AiFailureAndQueueTest` (degraded when AI down) |
| Load testing | BLOCKED | Not executed in STEP 41 (no load-test tooling in the repo; `scripts/perf-smoke.sh` gives latency snapshots only). |

## 9. E2E User Journeys

### 9a. Bash end-to-end scripts against the Docker stack (real AI)

`backend/tests/e2e_golden_path.sh` — **PASS, 93/93 checks** (`backend/storage/logs/e2e/e2e_golden_path.log`). Also available: `backend/tests/e2e_all.sh` (17 journey scripts from earlier steps).

### 9b. Playwright browser journeys (`frontend/tests/e2e`, Chromium, production build, real backend + AI)

| Spec | Journey | Tests |
|---|---|---|
| `01-auth.spec.ts` | 1 — register → dashboard → sign out → login; wrong credentials; anonymous redirect; inline validation; session survives reload | 5 |
| `02-course-setup.spec.ts` | 2 — course + 3 LOs + midterm through the forms; empty form rejected | 2 |
| `03-analysis-recommendations.spec.ts` | 3–4 — run AI analysis in the UI, see quality score & recommendations, faculty feedback; explanation of assistive role | 2 |
| `05-rubric-versions.spec.ts` | 5–6 — generate AI rubric (draft) → approve; create & finalize version | 1 |
| `07-submissions-grading.spec.ts` | 7 — submission → manual grading (client-side max-marks check) → GRADED → performance analysis | 1 |
| `08-analytics-reports.spec.ts` | 8 — analytics KPIs match API; generate & download report; unauthorized report message | 1 |
| `09-ui-states-accessibility.spec.ts` | error UI (network failure, 500, 404), loading and empty states, keyboard navigation, axe WCAG 2.1 A/AA on 12 pages | 9 |
| **Total** | | **21** |

Results:

| Run | Result |
|---|---|
| Each spec run individually during development | 21/21 PASS |
| First full-suite run (all 21 in one Chromium worker, cold `php artisan serve`) | **19 passed, 2 FAILED** — `01-auth › register…` (sign-out redirect not observed within 15 s: the logout POST was queued behind the dashboard's parallel requests on the single-threaded dev server; the request itself completed) and `07-submissions-grading` (the 4-second "Final marks saved." toast had already disappeared when asserted; the marks *were* saved — 7.5/10 visible in the snapshot). Both are timing assumptions in the tests, not application defects. |
| Fixes | sign-out redirect assertion given 60 s; grading test asserts on the persisted marks instead of the transient toast |
| Second full-suite run after the fixes | **21 passed, 0 failed** (10.5 min, exit code 0; `backend/storage/logs/e2e/playwright_full_rerun.log`) |

## 10. Golden Path Result

**PASS** — both implementations of the Golden Path pass:

* `backend/tests/Feature/GoldenPathTest.php` (PHPUnit, sqlite, live AI): 179 assertions in a single flow —
  register/login → course CSE-401 → 3 LOs → program + link → 3 POs → CO/PO mappings + analysis → assessment (100 marks / 120 min) → 8 questions → blueprint create/validate/finalize + validate-questions → unified AI analysis (`POST /ai/analyze-assessment`) → recommendation feedback → AI rubric generate → approve → version create → finalize (edit → 409) → 6 students via CSV import (48 answers) → UNDER_REVIEW → 48 faculty `finalize-grade` → GRADED → performance analysis (average cross-checked against raw marks) → analytics overview → reports ASSESSMENT_QUALITY (PDF), QUESTION_ANALYSIS (CSV), STUDENT_PERFORMANCE (XLSX), CO_COVERAGE (CSV), PO_COVERAGE (CSV) → downloads → privacy (no student identifier in any report) → stranger 403/404 → audit trail (`AI_ANALYSIS_COMPLETED`, `REPORT_GENERATION_COMPLETED`, `REPORT_DOWNLOADED`).
* `backend/tests/e2e_golden_path.sh` (Docker stack, MySQL, real AI incl. live question generation): 93/93 checks, integrity-check compared before/after (no new issues).

## 11. Failed Tests

| Test | Suite | Outcome |
|---|---|---|
| `01-auth.spec.ts › a new faculty member registers…` | Playwright, first full run | FAIL → fixed (test timing), see §9b |
| `07-submissions-grading.spec.ts › faculty grades…` | Playwright, first full run | FAIL → fixed (test asserted a transient toast), see §9b |
| Backend PHPUnit | — | 0 failures |
| Frontend Vitest | — | 0 failures |
| AI service pytest | — | 0 failures |

No failing test was skipped, deleted or marked as expected-failure.

## 12. Bugs Found

Found by the STEP 41 tests (all real, all reproduced before fixing):

1. **Submission could be marked GRADED with unreviewed answers** — `PATCH /submissions/{id}/status` accepted `GRADED` while answers still had no faculty final mark (`DatabaseIntegrityTest`).
2. **Dashboard greeting used the honorific** — "Welcome back, Dr." for a user named "Dr. Grace Hopper" (Journey 1).
3. **Silent AI analysis failure in the UI** — when the analysis request failed, the Analysis page showed no visible error (Journey 3 error-UI check).
4. **Raw server errors could reach the user** — 5xx bodies / network failures surfaced as technical text; no friendly mapping (`09-ui-states`).
5. **Accessibility (axe WCAG 2.1 AA) violations** — insufficient contrast (`sage-400`, amber text on Assessments), 12 filter `<select>`s without accessible names, progress bar without `aria-label`, invalid `<dl>` structure in analytics summary/AI-collab cards, scrollable analytics regions not keyboard-focusable.
6. **Playwright specs were being picked up by Vitest** (tooling).
7. **Compose port merge in the clean-start override** produced duplicate published ports (tooling).

## 13. Bugs Fixed

| # | Fix | Files |
|---|---|---|
| 1 | `changeStatus()` now throws 422 "`{n} answer(s) still need a faculty grade before this submission can be marked as graded.`" | `backend/app/Services/StudentSubmissionService.php` |
| 2 | Greeting skips honorifics (Dr., Prof., Mr., Ms., …) | `frontend/src/pages/Dashboard.tsx` |
| 3 | Visible `role="alert"` banner "Analysis could not run." with the server message | `frontend/src/pages/Analysis.tsx` |
| 4 | `friendlyStatusMessage()`; network failure → `ApiError(0, "Unable to reach the FacultyLens server…")`; leaked exception text replaced | `frontend/src/services/api.ts` (+ 8 unit tests) |
| 5 | `sage-400 → #68765F`, `amber-700`, `aria-label` on every filter select, `ProgressBar` label, proper `dt/dd`, `tabIndex={0}` on scroll regions — axe now reports **0 violations** on 12 pages | `tailwind.config.js`, `Assessments.tsx`, `Courses.tsx`, `Feedback.tsx`, `RecommendationsSection.tsx`, `FeedbackHistoryTable.tsx`, `RecommendationSection.tsx`, `ProgressBar.tsx`, `AnalyticsSummary.tsx`, `AnalyticsAiCollab.tsx`, `AnalyticsOutcomesPerformance.tsx` |
| 6 | `test.exclude: ['tests/e2e/**']` | `frontend/vite.config.ts` |
| 7 | `ports: !override` in the clean-test compose file | `scripts/docker-compose.clean-test.yml` |
| — | Two Playwright timing assumptions corrected (see §9b) | `tests/e2e/01-auth.spec.ts`, `tests/e2e/07-submissions-grading.spec.ts` |

## 14. Remaining Issues

| Issue | Severity | Status |
|---|---|---|
| Inter-grader consistency cannot be tested — no second-grader model | Informational | NOT_APPLICABLE (would be a new feature; out of scope per §47) |
| Redis-backed queue / Horizon not exercised (dev uses database driver; no Redis in dev) | Low | BLOCKED — verify on the production stack with `docker-compose.prod.yml` |
| Load testing not performed | Low | BLOCKED — no tooling in repo |
| Dev database has 2 pre-existing integrity findings (submissions 275/277, version 27) created before the new guards | Low (dev data only) | Recorded; not modified. A fresh seed has 0 findings. |
| `npm audit`: 2 moderate advisories in `react-router` requiring a v7 major upgrade (recorded in `docs/PRODUCTION_READINESS.md`) | Moderate | Open — deferred, not a STEP 41 change |
| Dev server (`php artisan serve`) is single-threaded; dashboard fires several parallel requests, so first-load latency on a cold container is high (2–10 s per request observed). Production uses php-fpm behind nginx. | Low (dev only) | Documented |
| Frontend bundle > 500 kB (Vite warning) | Low | Open — code-splitting is an optimisation, not a defect |

No P0/P1 defects are known to remain.

## 15. Final Acceptance Status

| Criterion | Status |
|---|---|
| Authentication E2E | PASS |
| Course workflow | PASS |
| LO workflow | PASS |
| CO/PO mapping | PASS |
| Assessment workflow | PASS |
| Document processing | PASS |
| AI health check | PASS |
| Question analysis | PASS |
| LO alignment | PASS |
| Similarity analysis | PASS |
| Complete AI analysis | PASS |
| Quality engine | PASS |
| Recommendation engine | PASS |
| Rubric workflow | PASS |
| Question generation workflow | PASS |
| Blueprint workflow | PASS |
| Assessment versioning | PASS |
| Student submission workflow | PASS |
| Faculty grading | PASS |
| Inter-grader consistency | NOT_APPLICABLE |
| Student performance analysis | PASS |
| Learning-gap analysis | PASS |
| Academic analytics uses real data | PASS |
| Institutional reporting | PASS |
| PDF export | PASS |
| CSV export | PASS |
| XLSX export | PASS |
| Privacy tests | PASS |
| Authorization tests | PASS |
| IDOR tests | PASS |
| AI failure handling | PASS |
| Database integrity tests | PASS |
| Redis/Horizon tests | NOT_APPLICABLE (dev) / BLOCKED (prod) |
| Docker clean-start test | PASS |
| Playwright critical journeys | PASS (21/21 on the full-suite re-run; first run 19/21 — two test-timing assumptions fixed, see §9b/§11) |
| Accessibility checks (axe WCAG 2.1 A/AA, keyboard) | PASS |
| Regression suite (`scripts/test-all.sh`) | PASS — backend 464 / tsc / vitest 261 / build / ruff / pytest 196 |
| Golden Path | PASS |
| No critical P0/P1 defects remain | PASS |

Academic safety rules (spec §46) verified during the run: recommendations are assistive and require faculty feedback; AI-generated questions and rubrics stay in draft until faculty approval; AI grading suggestions are labelled "Faculty review is required" and never become final marks (`finalize-grade` is a faculty action); finalized grades and versions are immutable; reports respect ownership and contain no student identifiers; no diagnostic claims are generated.

**Overall: STEP 41 acceptance criteria met**, with two items honestly recorded as NOT_APPLICABLE/BLOCKED (inter-grader consistency, Redis/Horizon in dev) and no hidden failures.
