# FacultyLens Bug Fix Report

STEP 42 — Bug Fixing & Regression Testing. Branch `feature/bug-fixing-regression` (chained after `feature/e2e-system-validation`).
Companion documents: `docs/BUG_REGISTER.md` (one row per defect, with reproduction, root cause, fix and test) and `docs/REGRESSION_TEST_MATRIX.md` (what was executed per module).

## 1. Summary

STEP 41 ended with every suite green, so STEP 42 could not simply replay failing tests. Instead the whole system was re-audited against the STEP 42 specification (authorization matrix, question/rubric/grading validation, versioning and job lifecycle, AI thresholds and contract, analytics cache, frontend API client, Docker/database) and **every suspected defect was first turned into a failing test**. Twelve register entries resulted; ten were fixed and verified end-to-end, two (P3) are documented as WONT_FIX with justification.

The most consequential findings were **not** in the areas STEP 41 had exercised through the happy path:

* the AI analysis "re-run" and "failure" paths **corrupted analysis history** (P1 ×2),
* deleting an assessment / course / submission **destroyed finalized grades and finalized versions** (P0, P1),
* finalized (RETURNED) grades were **still editable through the generic answer endpoints** (P1),
* three boundary classifiers (learning gap, similarity, LO alignment) could **disagree with the number they display** (P2 ×2),
* an expired server session left the SPA in a **dead logged-in shell** and a stale CSRF cookie required a manual reload (P2 ×2),
* `migrate:rollback` **failed on MySQL** (P3).

No feature was added, no test was weakened or removed, no assertion was hard-coded to pass. One pre-existing test (`AiIntegrationTest::test_recommendation_decision_state_is_preserved_across_reanalysis`) had encoded the buggy overwrite behaviour; it was corrected to assert the intended behaviour (previous version untouched, faculty decision carried to the new version) — documented in the register under BUG-001.

## 2. Total Bugs Found

| Severity | Found | Fixed & verified | WONT_FIX (documented) |
|---|---|---|---|
| P0 | 1 | 1 | 0 |
| P1 | 4 | 4 | 0 |
| P2 | 4 | 4 | 0 |
| P3 | 3 | 1 | 2 |
| **Total** | **12** | **10** | **2** |

(BUG-000 — the two STEP 41 Playwright timing assumptions — is carried in the register for traceability but was already fixed in STEP 41 and is not counted above.)

## 3. P0 Bugs

| ID | Title | Outcome |
|---|---|---|
| BUG-003 | `DELETE /assessments/{id}` and `DELETE /courses/{id}` cascaded through GRADED/RETURNED submissions, their answers, and FINALIZED assessment versions | Fixed — `Assessment::deletionBlocker()` → 409 with an explanatory message; drafts remain deletable |

## 4. P1 Bugs

| ID | Title | Outcome |
|---|---|---|
| BUG-001 | Re-running analysis overwrote version 1 in place (sync and async paths); async re-runs were silently skipped | Fixed — run-row lifecycle on `AnalysisReport` |
| BUG-002 | A failed re-analysis flipped the last completed report to `failed`, hiding results from UI/analytics/reports; job `failed()` typed `Exception` | Fixed — failures only ever mark the attempt; `failed(\Throwable)` |
| BUG-004 | Answers of RETURNED submissions could be edited / re-marked / added / deleted via generic answer endpoints | Fixed — `assertSubmissionWritable()` on every write path |
| BUG-009 | `DELETE /submissions/{id}` removed GRADED/RETURNED submissions with their finalized grades | Fixed — GRADED must be reopened first; RETURNED never deletable |

## 5. P2 Bugs

| ID | Title | Outcome |
|---|---|---|
| BUG-005 | Learning-gap band computed on the unrounded gap; displayed gap `5.00` could be ON_TARGET | Fixed — classify on the 2-dp value that is reported |
| BUG-006 | AI similarity / LO alignment status computed on raw cosine while the score is reported at 4 dp (`0.85` labelled HIGHLY_SIMILAR) | Fixed — shared `threshold_bands.py`, both analyzers classify the reported score |
| BUG-007 | 401 on a protected request → inline error only; app stayed "logged in" with everything failing | Fixed — session-expired event clears `AuthContext`, `ProtectedRoute` redirects |
| BUG-008 | Stale XSRF cookie → every mutation 419 until manual reload | Fixed — one CSRF refresh + replay for mutating requests |

## 6. P3 Bugs

| ID | Title | Outcome |
|---|---|---|
| BUG-012 | `migrate:rollback` failed on MySQL (index needed by FK dropped first); `notifications` table never dropped | Fixed — verified by `migrate:reset` (46 down, 0 tables left) in the clean-start test |
| BUG-010 | `decimal:2` casts serialise `marks`/`total_marks`/`awarded_marks` as strings on raw model responses while TS types say `number` | WONT_FIX in STEP 42 — no arithmetic path is affected (all computed endpoints cast to float); changing the cast would alter version `content_hash` inputs; recorded for a dedicated contract clean-up |
| BUG-011 | Analysis dashboard cache is user-scoped (collaborator may see ≤ 5-min-stale data after the owner re-runs) | WONT_FIX — read-only staleness, no data risk |

## 7. Bugs Fixed

| ID | Files changed |
|---|---|
| BUG-001/002 | `backend/app/Models/AnalysisReport.php` (+`currentFor`, `beginRun`, `completeRun`, `failRun`, `recordFailure`), `backend/app/Http/Controllers/Api/AiAnalysisController.php` (unified analysis, async dispatch, alignment / similarity / quality endpoints, `currentReportOrNew()`), `backend/app/Jobs/AnalyzeAssessmentJob.php` |
| BUG-003 | `backend/app/Models/Assessment.php` (`deletionBlocker()`), `AssessmentController::destroy`, `CourseController::destroy` |
| BUG-004/009 | `backend/app/Services/StudentSubmissionService.php` (`assertSubmissionWritable()`, `deleteSubmission` guard), `StudentAnswerController::destroy`, `StudentSubmissionController::destroy` (map `SubmissionException` → 422 instead of 500) |
| BUG-005 | `backend/app/Services/StudentPerformanceService.php::classify()` |
| BUG-006 | `ai-service/app/services/threshold_bands.py` (new), `semantic_similarity_analyzer.py`, `lo_matcher.py` |
| BUG-007/008 | `frontend/src/services/api.ts` (`SESSION_EXPIRED_EVENT`, auth-endpoint detection, 419 retry), `frontend/src/context/AuthContext.tsx` |
| BUG-012 | `backend/database/migrations/2026_09_13_100001_create_collaboration_tables.php::down()` |

## 8. Regression Tests Added

| Test | Guards |
|---|---|
| `backend/tests/Feature/Regression/AnalysisReportLifecycleRegressionTest.php` (4) | sync re-run → v2, v1 intact; failed re-run keeps v1 completed, status endpoint `failed`, history current = 1; async job re-run versions; async permanent failure marks only the attempt |
| `backend/tests/Feature/Regression/AcademicRecordDeletionGuardTest.php` (4) | assessment with graded submission → 409; with finalized version → 409; course with graded assessment → 409; draft assessment still deletable |
| `backend/tests/Feature/Regression/ReturnedSubmissionReadOnlyTest.php` (6) | RETURNED: marks, text, add, delete, finalize-grade all 422; GRADED still re-gradable; GRADED/RETURNED not deletable, reopened → deletable |
| `backend/tests/Feature/Regression/IdorMatrixRegressionTest.php` (3) | Faculty B denied on 66 object routes with no side effects; anonymous 401 on all; 7 list endpoints return nothing foreign |
| `backend/tests/Unit/LearningGapBoundaryTest.php` (12) | 4.99/5.00/9.99/10.00/19.99/20.00, `calculate()`-style percentages, reported gap ↔ band agreement, insufficient data |
| `backend/tests/Unit/BlueprintToleranceBoundaryTest.php` (4) | MATCH/CLOSE/MISMATCH at 0.5 and ±tolerance, float noise, NOT_CONFIGURED, custom tolerance |
| `ai-service/tests/test_threshold_reported_score.py` (12) | similarity 0.85/0.70/0.50 and alignment 0.70/0.50 on the *reported* score; both analyzers with controlled embeddings |
| `ai-service/tests/test_quality_rating_boundaries.py` (15) | 90/80/70/60 bands incl. 89.5 → GOOD, weights = 100, missing component renormalised, < 2 components → UNAVAILABLE, determinism |
| `frontend/src/tests/services/apiErrorMapping.test.ts` (+6) | 401 protected → event + message; 401 login → no event; 419 retry once → success; second 419 → error; GET not retried |
| `backend/tests/e2e_ai_outage.sh` (22 live checks) | spec §12 end-to-end: `docker compose stop ai-service` → controlled 502, no leak, v1 intact, readiness degraded → `start` → v2, 0 failed rows, history 2 |
| `scripts/clean-start-test.sh` (+4 checks) | `migrate:reset` 0 failures / 0 tables left → re-migrate 0 pending on a fresh MySQL |
| `scripts/test-all.sh` / `.ps1` | new opt-in `--outage` / `-Outage` stage |

Net new automated checks: **Laravel 464 → 497 tests, pytest 196 → 223, vitest 261 → 267**, plus 26 live-stack checks.

## 9. Security Issues Fixed

* **Authorization** — the 66-route IDOR matrix found **no** cross-faculty leak; the centralised `CourseAccessService` + policies hold. The matrix is now permanent.
* **Session handling (BUG-007)** — an expired/revoked server session now logs the client out instead of leaving an authenticated-looking shell; no token is ever stored in `localStorage` (Sanctum cookie session retained).
* **CSRF (BUG-008)** — a stale XSRF cookie is refreshed once and the request replayed; GETs and repeated 419s are never retried, so no replay amplification.
* Deletion guards (BUG-003/009) close a path by which an authorised user could irreversibly destroy academic records with a single request.

## 10. Data Integrity Issues Fixed

* Completed analysis reports are now immutable history (BUG-001/002): every run has its own row, `is_current` moves only on completion, failures are recorded separately, and the integrity-check invariant "one current analysis per assessment" is preserved (verified live: exactly 1 current row during and after an outage).
* Finalized grades and finalized versions can no longer be cascaded away (BUG-003/009) or edited (BUG-004).
* Learning-gap, similarity and alignment classifications always agree with the number displayed (BUG-005/006).
* Schema is fully reversible on MySQL (BUG-012).

## 11. AI Integration Issues Fixed

* Laravel ↔ FastAPI contract, API-key enforcement, timeouts, non-JSON/missing-key handling and response validation were audited and found correct (see register "verified" table).
* Failure semantics fixed (BUG-002): outage → 502/504 with a safe message, the *attempt* is `failed`, the last good result stays `completed`, readiness reports `degraded`, and the next successful run becomes the new current version. Verified with the real container stopped and restarted.
* Threshold consistency fixed in the service itself (BUG-006); quality bands, weights and renormalisation verified with new boundary tests.

## 12. Frontend Issues Fixed

* BUG-007 (session expiry → redirect), BUG-008 (419 retry). `tsc` clean, 267 unit tests, production build, and all 21 Playwright journeys (incl. axe WCAG 2.1 A/AA on 12 pages) pass against the fixed backend. No new `any` types were introduced; the one pre-existing `questions: any[]` in `aiAnalysisService.ts` is noted in the register audit table.

## 13. Infrastructure Issues Fixed

* BUG-012 migration reversibility.
* `scripts/clean-start-test.sh` now proves migrate → reset → migrate on a fresh MySQL volume in an isolated compose project (129 s, 72 tables, seed OK, readiness `ready`, integrity 0, no error lines).
* `scripts/test-all.sh|ps1` gained the `--outage` stage; the script still fails if any critical suite fails.
* Redis/Horizon: unchanged from STEP 41 — dev/test use the database driver by design; production configuration is validated by `ProductionReadinessTest`, but no Redis instance exists in this environment, so it remains NOT_APPLICABLE (dev) / to be verified on the production stack.

## 14. Remaining Known Issues

| Issue | Severity | Status |
|---|---|---|
| BUG-010 decimal-as-string on raw model responses vs TS `number` types | P3 | Deferred, documented (no arithmetic path affected) |
| BUG-011 user-scoped 5-minute analysis dashboard cache | P3 | Deferred, documented |
| No manual question CRUD endpoint (questions enter via upload/extraction, approved generation, version restore — all validated) | Feature gap | Out of scope per spec §46 |
| Inter-grader consistency needs a second-marker data model | Feature gap | NOT_APPLICABLE (unchanged from STEP 41) |
| Redis/Horizon queue not exercised in dev | Low | BLOCKED (prod-only) |
| `react-router` moderate advisories (v7 major upgrade), bundle > 500 kB | Low | Unchanged, tracked in `docs/PRODUCTION_READINESS.md` |

**No known P0/P1/P2 defects remain open.**

## 15. Final Regression Result

All numbers are from real runs on 2026-09-12 after the last code change.

| Suite | Result |
|---|---|
| Laravel `php artisan test` | **497 passed**, 0 failed (4 071 assertions) |
| AI service `pytest -q` | **223 passed**, 0 failed |
| AI service `ruff check app tests` | clean |
| Frontend `tsc --noEmit` | 0 errors |
| Frontend `vitest run` | **267 passed**, 0 failed (21 files) |
| Frontend `vite build` | built (chunk-size warning only) |
| Playwright `npx playwright test` (Chromium, production build, real API + AI) | **21 passed**, 0 failed (8.8 min) |
| `bash backend/tests/e2e_ai_outage.sh` (live stop/start of `ai-service`) | **PASS** |
| `bash scripts/clean-start-test.sh` (isolated Docker project, fresh volumes, migrate → reset → migrate → seed → journey → integrity) | **PASS** |
| `bash backend/tests/e2e_golden_path.sh` (Docker, live AI, 93 checks) | **PASS** — see `backend/storage/logs/e2e_golden_path_step42.log` |

Every fixed bug has a regression test that failed before the fix and passes after it; those tests are part of the default suites and will run on every future `scripts/test-all.sh`.
