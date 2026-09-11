# FacultyLens API Reference

Base URL: `https://<host>/api` (development: `http://127.0.0.1:8080/api`). All responses are JSON envelopes:

```json
{ "status": "success" | "error", "message": "…", "data": { … }, "errors": { "field": ["…"] } }
```

## Authentication (Laravel Sanctum, cookie sessions)

The SPA and API share a first-party session cookie; there are no bearer tokens in the browser.

1. `GET /sanctum/csrf-cookie` — sets `XSRF-TOKEN`; send its value back as `X-XSRF-TOKEN` on every mutating request.
2. Requests must carry `Origin`/`Referer` of an allowed frontend (`CORS_ALLOWED_ORIGINS`, `SANCTUM_STATEFUL_DOMAINS`).

| Method | Endpoint | Auth | Body / notes | Success | Errors |
|---|---|---|---|---|---|
| POST | `/auth/register` | — (throttle:auth 20/min) | `name, email, department, designation, password, password_confirmation` (min 6) | 201 `{user}` | 422 |
| POST | `/auth/login` | — (throttle:auth) | `email, password, remember?` | 200 `{user}` | 401 invalid credentials, 422 |
| GET | `/auth/user` | session | — | 200 `{user}` | 401 |
| PATCH | `/auth/user` | session | `name?, department?, designation?` (email is immutable; `role` ignored unless admin) | 200 | 422 |
| POST | `/auth/change-password` | session (throttle:auth) | `current_password, password, password_confirmation` (min 6, different) | 200 | 422 |
| POST | `/auth/logout` | session | — | 200 | 401 |

Common status codes across the API: `401` unauthenticated · `403` not authorized (course role matrix / policy) · `404` missing · `409` state conflict (e.g. finalized version, duplicate analysis) · `419` CSRF/session expired · `422` validation · `429` rate limited · `503` dependency unavailable (AI service).

## Authorization model

- Course access = STEP 34 matrix in `config/collaboration.php`: `OWNER` (course.user_id or ADMIN), `EDITOR`, `REVIEWER`, `VIEWER`. Abilities such as `view`, `edit_assessment`, `view_student_data`, `manage_collaborators` are resolved server-side by `CourseAccessService`; controllers/policies never compare ids directly.
- `User.role` is `FACULTY` (default) or `ADMIN`. Only ADMIN may use institution/department report scopes and see all AI-evaluation runs.
- Every resource id in a URL is re-authorized on the server (IDOR sweep in `tests/Feature/ProductionReadinessTest.php`).

## Health

| Method | Endpoint | Auth | Purpose |
|---|---|---|---|
| GET | `/health` | — | Liveness: process + DB connection (200 / 503) |
| GET | `/health/ready` | — | Readiness: `components.{database,cache,storage,queue,ai_service}` as `ok`/`error`; 503 when a core dependency fails; `?details=1` diagnostics for authenticated admins only |

## Courses & outcomes

| Method | Endpoint | Ability | Notes |
|---|---|---|---|
| GET/POST | `/courses` | member / owner | list (paginated) / create `{course_code, course_name, semester, academic_year, credits, status, description?, program_id?}` |
| GET/PUT/DELETE | `/courses/{course}` | view / edit_course / delete_course | |
| GET/POST | `/courses/{course}/learning-outcomes` | view / edit_outcomes | `{code, description, cognitive_level, sort_order}` |
| PUT/DELETE | `/learning-outcomes/{lo}` | edit_outcomes | |
| GET/POST | `/courses/{course}/materials`, `/courses/{course}/documents` | view / upload_document (throttle:uploads) | PDF/DOCX/TXT ≤ 20 MB; MIME + extension validated; private disk |
| GET | `/documents/{document}`, `/documents/{document}/download` | view | authenticated stream; no public URL |
| GET/POST | `/courses/{course}/co-po-mapping`, `/programs`, `/programs/{program}/outcomes` | view / edit_outcomes | STEP 31 |

## Assessments, questions, blueprints, versions

| Method | Endpoint | Ability | Notes |
|---|---|---|---|
| GET/POST | `/courses/{course}/assessments` | view / edit_assessment | `{title, type, total_marks>0, duration_minutes>0?, assessment_date?, status}` |
| GET/PUT/DELETE | `/assessments/{assessment}` | view / edit_assessment | |
| GET/POST | `/assessments/{assessment}/questions` | view / edit_assessment | `{question_number, question_text, question_type, marks≥0, difficulty_level, cognitive_level, learning_outcome_id?}` (≤ 200 per assessment) |
| GET/POST/PUT | `/assessments/{assessment}/blueprint`, `/blueprints/{blueprint}` | view / edit_assessment | STEP 37; `POST /blueprints/{b}/validate`, `/finalize`, `GET /blueprints/{b}/comparison` |
| GET/POST | `/assessments/{assessment}/versions` | view / edit_assessment | STEP 38; server-generated numbering |
| GET | `/assessments/{assessment}/versions/{version}` | view | |
| PUT | `/assessment-versions/{version}` | edit_assessment | DRAFT/IN_REVIEW only → 409 otherwise |
| POST | `/assessment-versions/{version}/submit-review` · `/approve` · `/finalize` · `/archive` · `/restore` · `/validate` | edit_assessment | finalize archives the previous FINALIZED version; never edits live questions |
| GET | `/assessment-versions/{version}/compare/{other}` · `/analysis` · `/blueprint` | view | |

## AI analysis (throttle:ai-analysis, 30/min)

| Method | Endpoint | Ability | Notes |
|---|---|---|---|
| GET | `/ai/health` | session | AI service reachability |
| POST | `/ai/analyze-assessment` | edit_assessment | `{assessment_id}` → quality, alignment, similarity, recommendations; 409 if an analysis is already running; 503 when AI unavailable (“AI analysis is temporarily unavailable”) |
| POST | `/ai/analyze-assessment-quality`, `/ai/analyze-alignment`, `/ai/detect-similarity` … | edit_assessment | component analyses |
| GET | `/assessments/{assessment}/analysis`, `/analysis/history`, `/analysis/{id}` | view | STEP 19 history bound to `assessment_version_id`; STALE when content changed |
| POST | `/recommendations/{recommendation}/feedback` | comment | `{decision: ACCEPTED|DISMISSED|REVIEWED, usefulness_rating?}` |

## Rubrics, submissions, grading

| Method | Endpoint | Ability | Notes |
|---|---|---|---|
| POST | `/questions/{question}/rubric/generate` | edit_assessment (throttle:ai-analysis) | AI draft → `DRAFT` |
| GET/PUT | `/rubrics/{rubric}` · `POST /rubrics/{rubric}/approve` | view / edit_assessment | edits to an APPROVED rubric create a new version |
| GET/POST | `/students` | — (own students) | `{student_identifier (^[\w\-./]+$), name, section?, email?}` |
| GET/POST | `/assessments/{assessment}/submissions` | view_student_data | `{student_id, submission_identifier?}`; `POST …/submissions/import` (CSV, throttle:uploads) |
| GET | `/submissions/{submission}` · `PATCH /submissions/{submission}/status` | view_student_data / grade | state machine `DRAFT→SUBMITTED→UNDER_REVIEW→GRADED→RETURNED` |
| POST | `/submissions/{submission}/answers` · `PUT /student-answers/{answer}` | grade | `awarded_marks` 0 ≤ x ≤ question marks; faculty value is authoritative |
| POST | `/student-answers/{answer}/ai-grading` · GET same · `POST …/decision` | grade | AI suggestion stored separately (`suggested_marks`); decision ACCEPTED/MODIFIED/REJECTED never overwrites `awarded_marks` automatically |

## Performance & analytics

| Method | Endpoint | Ability | Notes |
|---|---|---|---|
| GET/POST | `/assessments/{assessment}/performance` · `/performance/analyze` | view_student_data | STEP 30; only finalized grades; statuses STRONG/ON_TARGET/MINOR_GAP/MODERATE_GAP/HIGH_GAP/INSUFFICIENT_DATA |
| GET | `/analytics/overview?course_id&semester&academic_year&assessment_type&start_date&end_date&fresh` | own scope | STEP 36 aggregates (cached 5 min, key includes user + data version) |
| GET | `/analytics/filters` · `/analytics/compare?assessment_ids[]` · `/analytics/export?format=pdf|csv` · `/analytics/courses/{course}[/{section}]` | view | |

## Institutional reports (STEP 39)

| Method | Endpoint | Notes |
|---|---|---|
| GET | `/reports/types` | types/scopes/formats the caller may use |
| GET | `/reports/filters?report_type&scope_type` | access-scoped options + applicable keys |
| POST | `/reports/preview` | `{report_type, scope_type, filters}` → summary, metadata, truncated tables, `has_data`, `will_queue`; 403 unauthorized scope, 422 invalid filters |
| POST | `/reports` | + `format: PDF|CSV|XLSX` → 201 (sync) / 202 (queued); 422 “No data available for the selected filters.” |
| GET | `/reports` · `/reports/{report}` | own reports |
| GET | `/reports/{report}/download` | policy-checked private stream; 409 not ready, 410 expired |
| DELETE | `/reports/{report}` | not while PROCESSING |

## Collaboration, chat, generation, evaluation

- `GET/POST /courses/{course}/collaboration`, invitations (`throttle:collaboration-invite`), comments (`throttle:collaboration-comment`).
- `POST /academic-chat/sessions`, `POST /academic-chat/sessions/{session}/messages` (`throttle:academic-chat`) — retrieval is limited to the caller's authorized course documents; document text is treated as untrusted context.
- `POST /question-generation` (`throttle:question-generation`) — drafts require faculty review before they enter an assessment.
- `/ai/evaluation/*` — STEP 35 datasets, runs, metrics (`Not evaluated` when no run exists).

## Validation and limits (server-enforced)

`MAX_DOCUMENT_SIZE_MB=20`, `MAX_DOCUMENT_TEXT_LENGTH=2000000`, `MAX_QUESTIONS_PER_ASSESSMENT=200`, `MAX_PREVIOUS_QUESTIONS_PER_ANALYSIS=5000`; marks ≥ 0, percentages 0–100, counts > 0, enum values checked by FormRequests; mass assignment restricted by model `$fillable`.
