# FacultyLens Regression Test Matrix

STEP 42 — every cell marked **✓** was executed in this step; **—** means no test of that kind exists for the module (not "assumed passing"). Status reflects the final regression run recorded in `docs/BUG_FIX_REPORT.md` §15.

Legend for columns: **Unit** = pure logic (PHPUnit `tests/Unit`, pytest service tests) · **Integration** = Laravel feature tests against sqlite in-memory with faked or live AI, FastAPI TestClient · **E2E** = Playwright journeys against the production build + Docker stack, or bash journeys over the real HTTP API · **Security** = authorization / IDOR / CSRF / rate-limit / privacy assertions · **Regression** = a test that was written to pin a bug found in STEP 41/42 (see `BUG_REGISTER.md`).

| Module | Unit | Integration | E2E | Security | Regression | Status |
|---|---|---|---|---|---|---|
| Authentication (Sanctum session, CSRF, logout, expiry) | — | ✓ `AuthTest`, `SecurityTest` | ✓ `01-auth.spec.ts`, clean-start API journey | ✓ `SecurityTest`, `IdorMatrixRegressionTest` (401 on 66 routes) | ✓ BUG-007/008 `apiErrorMapping.test.ts` | PASS |
| Authorization / IDOR (courses, assessments, documents, reports, students, submissions, grades, rubrics, versions, blueprints, AI artefacts, collaboration, analytics) | — | ✓ `CrossFacultySecurityE2ETest`, `CollaborationTest`, per-module tests | ✓ Golden Path stranger checks | ✓ `IdorMatrixRegressionTest` (3 tests / 66 routes / list scoping) | ✓ (matrix added in STEP 42) | PASS |
| Courses / Learning outcomes / Programs | — | ✓ `CourseManagementTest`, `CoPoMappingTest` | ✓ `02-course-setup.spec.ts`, Golden Path | ✓ IDOR matrix | ✓ BUG-003 `AcademicRecordDeletionGuardTest` (course delete guard) | PASS |
| Assessments / questions / question paper | — | ✓ `AssessmentManagementTest`, `DatabaseIntegrityTest` | ✓ Golden Path (8 questions), Journey 2 | ✓ IDOR matrix | ✓ BUG-003 (assessment delete guard) | PASS |
| Document processing (PDF/DOCX/TXT, empty, corrupt, oversized, unsupported, traversal name) | — | ✓ `DocumentProcessingTest`, `DocumentProcessingResilienceTest` | ✓ `e2e_all.sh` document journeys | ✓ download ownership (`CollaborationTest`, IDOR matrix) | ✓ existing STEP 41 resilience tests re-run | PASS |
| AI integration & failure handling (Laravel ↔ FastAPI, 502/504, retry, recovery) | — | ✓ `AiIntegrationTest`, `AiFailureAndQueueTest`, `UnifiedAiAnalysisApiTest`, `LivePipelineEndToEndTest` | ✓ `backend/tests/e2e_ai_outage.sh` (live `docker compose stop/start ai-service`), `09-ui-states` outage test | ✓ no stack traces (`AiFailureAndQueueTest`, outage script) | ✓ BUG-001/002 `AnalysisReportLifecycleRegressionTest` (4) | PASS |
| AI analysis history / versions of analysis | — | ✓ `AnalysisHistoryTest`, corrected `AiIntegrationTest::…preserved_across_reanalysis` | ✓ outage script (history lists v1 + v2) | ✓ `analysis/{id}` ownership | ✓ BUG-001 | PASS |
| Question analysis / LO alignment / similarity thresholds | ✓ pytest `test_threshold_boundaries.py`, `test_threshold_reported_score.py` | ✓ `AiQuestionAnalysisTest`, `LearningOutcomeAlignmentTest`, `SemanticSimilarityTest`, pytest `test_alignment.py`, `test_similarity.py` | ✓ Golden Path live analysis | — | ✓ BUG-006 (12 tests) | PASS |
| Quality engine (weights 20/20/15/15/15/15, bands, missing data, determinism) | ✓ pytest `test_quality_rating_boundaries.py` (15), `test_quality_engine.py` | ✓ `AssessmentQualityEngineTest`, `AssessmentQualityScoreNormalizationTest` | ✓ Journey 3 | — | ✓ (boundary tests added) | PASS |
| Recommendation engine & faculty feedback | ✓ pytest `test_recommendation_engine.py` | ✓ `RecommendationEngineTest`, `RecommendationFeedbackTest`, `RecommendationTraceabilityTest` | ✓ `03-analysis-recommendations.spec.ts` | ✓ feedback ownership (IDOR matrix) | ✓ BUG-001 (decision carried to new version, history untouched) | PASS |
| Rubrics (sum = marks, approve, regenerate, archive) | ✓ pytest `test_rubric_generator.py`, `test_rubric_alignment.py` | ✓ `RubricTest`, `RubricAlignmentTest` | ✓ `05-rubric-versions.spec.ts`, Golden Path | ✓ IDOR matrix (list/show/update/approve/delete) | ✓ existing invariants re-run | PASS |
| Question generation (constraints, drafts → approval → official) | ✓ pytest `test_question_generator.py` | ✓ `QuestionGenerationTest` | ✓ Golden Path (live generation + approval + add) | ✓ IDOR matrix | ✓ existing | PASS |
| Blueprint (MATCH/CLOSE/MISMATCH/NOT_CONFIGURED, finalize) | ✓ `BlueprintToleranceBoundaryTest` (4) | ✓ `AssessmentBlueprintTest` | ✓ Golden Path | ✓ IDOR matrix | ✓ (boundary tests added) | PASS |
| Assessment versioning (finalized immutable, V1 preserved, submissions pinned) | — | ✓ `AssessmentVersionTest`, `DatabaseIntegrityTest` | ✓ `05-rubric-versions.spec.ts`, Golden Path | ✓ IDOR matrix | ✓ BUG-003 (finalized version blocks delete) | PASS |
| Student submissions (state machine, duplicates, cross-assessment answer, CSV import) | — | ✓ `StudentSubmissionTest`, `DatabaseIntegrityTest` | ✓ `07-submissions-grading.spec.ts`, Golden Path | ✓ `view_student_data` role tests, IDOR matrix | ✓ BUG-004/009 `ReturnedSubmissionReadOnlyTest` (6) | PASS |
| Grading (0 ≤ marks ≤ max, AI suggestion never final, finalized authoritative) | — | ✓ `AiGradingTest`, `StudentSubmissionTest` | ✓ Journey 7, Golden Path (48 finalize-grade) | ✓ IDOR matrix (ai-grade, finalize-grade) | ✓ BUG-004/009 | PASS |
| Inter-grader consistency | — | — | — | — | — | NOT_APPLICABLE (single grader of record; no second-marker model) |
| Student performance & learning gaps (only finalized grades; <5/5/10/20 bands) | ✓ `LearningGapBoundaryTest` (12) | ✓ `StudentPerformanceTest` | ✓ Journey 7, Golden Path | ✓ IDOR matrix | ✓ BUG-005 | PASS |
| CO / PO mapping | — | ✓ `CoPoMappingTest` | ✓ Golden Path | ✓ IDOR matrix | ✓ existing | PASS |
| Academic analytics (real data, cache invalidation) | — | ✓ `AcademicAnalyticsTest` | ✓ `08-analytics-reports.spec.ts` (KPIs vs API), Golden Path | ✓ scope service tests, IDOR matrix | ✓ existing | PASS |
| Institutional reporting (PDF / CSV / XLSX, version isolation) | — | ✓ `InstitutionalReportTest`, `AssessmentReportTest` | ✓ Journey 8, Golden Path (5 formats/types, magic bytes) | ✓ download/delete ownership (IDOR matrix) | ✓ existing | PASS |
| Report privacy (no student identifiers by default) | — | ✓ `InstitutionalReportTest` | ✓ Golden Path (byte scan for `GP-STU-001`) | ✓ same | ✓ existing | PASS |
| Collaboration (members, roles, comments, mentions, notifications) | — | ✓ `CollaborationTest` (17) | ✓ `e2e_collaboration.sh` | ✓ role matrix, IDOR matrix | ✓ existing | PASS |
| Notifications | — | ✓ `CollaborationTest::summary_and_notifications_are_user_scoped` | — | ✓ user-scoped | ✓ code audit (dedup + actor excluded) | PASS |
| Queue / jobs (database driver: pending → processing → completed, failed → retry) | — | ✓ `AiFailureAndQueueTest` (retry then success, exhausted → failed_jobs, every job declares tries/timeout/failed) | ✓ clean-start (worker alive, 0 failed jobs), Golden Path | — | ✓ BUG-002 (`failed(\Throwable)`, run-row failure) | PASS |
| Redis / Horizon | — | ✓ `ProductionReadinessTest` (config) | — | — | — | NOT_APPLICABLE (dev/test use database driver; prod-only, documented in `docs/DEPLOYMENT.md`) |
| Database (migrations from zero, FKs, unique, rollback) | — | ✓ `DatabaseSchemaIntegrityTest`, `DatabaseIntegrityTest` | ✓ `scripts/clean-start-test.sh` (46 migrations, `migrate:reset` 0 failures/0 tables left, re-migrate, seed, integrity 0) | — | ✓ BUG-012 | PASS |
| Docker / clean install | — | — | ✓ `scripts/clean-start-test.sh` (isolated project, fresh volumes, 129 s) | — | ✓ | PASS |
| Frontend routing, states, forms, accessibility | ✓ vitest (21 files / 267 tests) | — | ✓ `09-ui-states-accessibility.spec.ts` (axe WCAG 2.1 A/AA on 12 pages, keyboard) | ✓ 401/419 handling tests | ✓ BUG-007/008 | PASS |
| API contract (frontend ↔ Laravel) | ✓ `apiErrorMapping.test.ts`, `apiServices.test.ts` | — | ✓ all Playwright journeys run against the real API | — | ✓ | PASS (BUG-010 decimal strings documented, WONT_FIX) |

## Suites executed in the final regression

| Suite | Command | Result |
|---|---|---|
| Laravel | `php artisan test` | 496 passed (4 064 assertions) |
| AI service | `pytest -q` / `ruff check app tests` | 223 passed / clean |
| Frontend | `tsc --noEmit` / `vitest run` / `vite build` | 0 errors / 267 passed / built |
| Playwright | `npx playwright test` | 21 passed (8.8 min) |
| Live AI outage | `bash backend/tests/e2e_ai_outage.sh` | PASS (22/22 checks) |
| Clean Docker start | `bash scripts/clean-start-test.sh` | PASS (incl. rollback/re-migrate) |
| Golden Path (Docker, live AI) | `bash backend/tests/e2e_golden_path.sh` | see `BUG_FIX_REPORT.md` §15 |
