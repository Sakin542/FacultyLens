# Academic Analytics Dashboard (STEP 36)

`/analytics` answers "how are my assessments, outcomes, students and AI features doing?" from the data FacultyLens already
stores. It is a decision-support layer: **analytics = evidence + trends + signals, never an automatic decision**.

## Architecture

```text
Courses → Assessments → Questions → AI Analysis (STEP 13/11/12) → CO/PO (STEP 31) → Finalized grades / STEP 30 runs → Recommendations → Trends
                                                        ↓
                              AnalyticsScopeService (authorized course/assessment ids + data version)
                                                        ↓
   AssessmentAnalytics · OutcomeAnalytics · PerformanceAnalytics · AiAnalytics · CollaborationAnalytics
                                                        ↓
                              AcademicAnalyticsService::overview()  (cached per user + filters + data version)
                                                        ↓
                          GET /api/analytics/*  →  frontend/src/pages/AcademicAnalytics.tsx
```

| Piece | Location |
|---|---|
| Config | `backend/config/analytics.php` (`ANALYTICS_*` env vars: cache TTL, difficulty targets/bands, limits, explanations) |
| Scope/authorization | `App\Services\Analytics\AnalyticsScopeService` — starts from `CourseAccessService::accessibleCourses`; student-level aggregates only for courses where the user has `view_student_data` (OWNER/EDITOR) |
| Services | `App\Services\Analytics\{Assessment,Outcome,Performance,Ai,Collaboration}AnalyticsService`, aggregator `AcademicAnalyticsService` (`App\Services\AcademicAnalyticsService` is the spec-named entry point) |
| Controller | `App\Http\Controllers\Api\AcademicAnalyticsController` |
| Export | `resources/views/reports/academic-analytics-pdf.blade.php` (dompdf), CSV via `AcademicAnalyticsService::toCsv` |
| Frontend | `types/analytics.ts`, `services/academicAnalyticsService.ts`, `components/analytics/*`, `pages/AcademicAnalytics.tsx` |

## API

All routes require Sanctum auth and return `{status, message, data}`.

| Route | Purpose |
|---|---|
| `GET /api/analytics/overview` | Unified overview for the authenticated user's accessible courses |
| `GET /api/analytics/filters` | Filter options (courses, assessments, semesters, years, types) limited to accessible courses |
| `GET /api/analytics/courses/{course}` | Overview scoped to one course (403 unless `view_analysis`) |
| `GET /api/analytics/courses/{course}/{assessments|performance|outcomes|ai|similarity|history}` | Section subsets; `history` groups assessments of every accessible term with the same course code |
| `GET /api/analytics/compare?assessment_ids[]=…` | Side-by-side comparison of 2–6 authorized assessments |
| `GET /api/analytics/export?format=pdf|csv|json` | Audited export (`ANALYTICS_EXPORTED`) of the filtered overview |

Filters: `course_id`, `assessment_id`, `semester`, `academic_year`, `assessment_type`, `start_date`, `end_date` (applied to
`COALESCE(assessment_date, created_at)`), `sort` (question table: `worst|gap|number|co`), `fresh=1` (bypass cache).
Filters affect **every** section; a `course_id` the user cannot access returns 403.

### Response sections

`kpis`, `assessment_quality` (+ dated `trend`), `difficulty`, `cognitive`, `learning_outcomes`, `program_outcomes`,
`performance` (+ `trend`), `learning_gaps`, `question_performance`, `topic_performance`, `similarity`, `question_bank`,
`rubrics`, `grading`, `inter_grader`, `ai_evaluation` (+ `trend`), `recommendations`, `collaboration` (+ audit `activity`),
`assessments`, `attention_areas`, `scope`, `meta`.

## Calculation rules (reused, not reinvented)

* **Quality**: current completed `analysis_reports.overall_score`; ratings via `AssessmentReportService::getRatingLabel` (EXCELLENT ≥90, GOOD ≥80, FAIR ≥70, NEEDS_REVIEW ≥60, else REQUIRES_ATTENTION).
* **Difficulty**: question-count share of `COALESCE(difficulty_level, ai_difficulty_level)` vs STEP 13 targets 30/50/20. Balance status from total absolute deviation: `> 45` SIGNIFICANTLY_UNBALANCED (STEP 13 rule), `> 15` SLIGHTLY_UNBALANCED (reporting band), else BALANCED.
* **Bloom**: question-count share of `COALESCE(cognitive_level, ai_cognitive_level)`; no institutional targets are assumed.
* **LO coverage**: STEP 11 alignments of the current report. Coverage % = strong / aligned questions; an outcome is *covered* when it has ≥1 STRONG alignment (`performance.lo_alignment_levels`).
* **PO coverage**: current STEP 31 CO/PO run per course (`po_evidence`). Courses without a program → "PO analysis is not configured".
* **Student performance**: only `FACULTY_REVIEWED`/`FINALIZED` submissions with `REVIEWED` answers and awarded marks (same predicate as `StudentPerformanceService::finalizedAnswersQuery`). Average = Σ awarded / Σ max (marks-weighted, as STEP 30); median/min/max over per-submission percentages. Gap/classification via `StudentPerformanceService::gap()/classify()` — fewer than `min_responses_for_gap_analysis` responses ⇒ `INSUFFICIENT_DATA`, never a gap.
* **Learning gaps / question / topic performance**: read from current STEP 30 runs; topics are response-weighted across runs.
* **Similarity**: STEP 12 statuses of the current report (Potential Duplicate ≥0.85, Highly Similar ≥0.70, Somewhat Similar ≥0.50).
* **AI grading**: current `ai_grading_results` vs final `student_answers.awarded_marks` on finalized submissions — MAE, signed difference, exact agreement; faculty decisions counted. AI suggestions are never presented as official grades.
* **Inter-grader (STEP 29)**: not deployed → reported as unavailable, never fabricated.
* **AI evaluation**: `AiEvaluationService::overview` headline metrics for evaluated tasks only; unevaluated tasks show "Not evaluated yet".
* **Recommendations**: `pending` = active, plus accepted/dismissed/reviewed; STEP 20 feedback shown as a *Faculty Interaction Signal*.
* **Collaboration**: active collaborators, discussions (ACTIVE/RESOLVED top-level comments — bodies are never exposed), pending invitations, audit-log activity families.
* **Attention areas**: HIGH GAP → quality issue → weak CO/PO coverage → similarity → cognitive diversity → difficulty, capped by `attention_limit`.

## Data freshness & caching

The overview is cached for `ANALYTICS_CACHE_TTL` seconds under `analytics:overview:{user}:{sha1(filters|dataVersion)}`.
`dataVersion` is a cheap fingerprint (counts, `MAX(updated_at)` and content-sensitive sums for question difficulty/Bloom/LO,
awarded marks, current analysis/performance runs, CO/PO mappings, recommendations, evaluation runs) so any relevant change
produces a new key — cached analytics can never be served after a question, grade, analysis or mapping changes. `meta`
reports `generated_at`, `cached` and `cache_ttl_seconds`; the UI shows "Last calculated … Data may be up to N minutes old".
Cache keys are per user, so one faculty member's aggregates are never served to another.

## Authorization & privacy

* Scope always starts from `CourseAccessService::accessibleCourses(user)`; REVIEWER/VIEWER collaborators receive analysis
  aggregates but `performance`, `learning_gaps`, `question_performance`, `topic_performance` and `grading` are restricted
  (`scope.student_data_restricted = true`).
* Aggregates contain no student names, identifiers or answer text; comment bodies are never included.
* Course-level endpoints return 403 for non-members; `compare` returns 403 when none of the requested assessments is authorized.

## Insufficient data

KPIs are `null` (rendered **N/A**) when no underlying records exist; sections render explicit empty states
("No finalized grades are available yet.", "No AI evaluation has been completed yet.", "PO analysis is not configured…").

## Tests

* Backend: `tests/Feature/AcademicAnalyticsTest.php` — hand-verified difficulty (3/5/2 → 30/50/20), Bloom, LO coverage,
  quality averages (superseded versions ignored), STEP 30 performance (8/7/6/9 of 10 → 75%, INSUFFICIENT_DATA under 5
  responses), gaps, grading MAE, similarity, recommendations, filters, course isolation/roles, compare, history, exports,
  caching, and the real-data verification (Medium → Hard changes the distribution).
* Frontend: `src/tests/components/academicAnalytics.test.tsx`.
* E2E: `backend/tests/e2e_academic_analytics.sh` (login → analytics → filters → sections → source change → export).
