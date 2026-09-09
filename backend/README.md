# FacultyLens — Laravel Backend API

Academic Decision Support System REST API service built with Laravel 12, MySQL 8.0, and Docker Compose.

---

## 1. System Requirements

* **Docker** (v20.10+ / Docker Desktop)
* **Docker Compose** (v2.0+)

---

## 2. Service Architecture & Port Mappings

| Service | Container Internal Address | Host Exposed URL / Port | Purpose |
| :--- | :--- | :--- | :--- |
| **Laravel App** | pp:8000 | http://127.0.0.1:8080 | REST API service & Health Check |
| **MySQL Database** | mysql:3306 | 127.0.0.1:3307 | Relational database engine |
| **phpMyAdmin** | phpmyadmin:80 | http://127.0.0.1:8081 | Web database management GUI |
| **Queue Worker** | queue-worker | — | `php artisan queue:work` (database driver) for asynchronous AI jobs (STEP 23/27: assessment analysis, AI grading assistance). Outside Docker run `php artisan queue:work` alongside the app. |

### STEP 27: AI Grading Assistance endpoints

AI output is a **suggestion**; faculty final marks are stored separately on `student_answers.awarded_marks`
and are never set automatically. All routes are Sanctum-protected and authorized via Course → Assessment → Submission → Answer ownership.

| Method | Route | Purpose |
| :--- | :--- | :--- |
| POST | `/api/student-answers/{answer}/ai-grade` | Queue an AI grading run against the approved rubric (202). Idempotent while pending; 409 if a fresh completed result exists (use regenerate). 422 without an approved rubric or for image-only answers. |
| GET | `/api/student-answers/{answer}/ai-grading` | Current result with criterion breakdown, evidence, missing elements and staleness flags. |
| GET | `/api/student-answers/{answer}/ai-grading/history` | All runs, newest first (old runs are preserved). |
| POST | `/api/ai-grading/{result}/regenerate` | New evaluation run (202). |
| POST | `/api/ai-grading/{result}/reject` | Faculty rejects the suggestion; marks untouched. |
| POST | `/api/student-answers/{answer}/finalize-grade` | Faculty final marks (+ optional feedback, decision). Validates `0 ≤ marks ≤ question.marks`. |
| PUT | `/api/student-answers/{answer}/final-grade` | Same as finalize (update). |

E2E: `bash backend/tests/e2e_grading.sh` against the Docker stack.

### STEP 28: Answer ↔ Rubric Alignment endpoints

Alignment describes how well an answer addresses each approved-rubric criterion (`STRONG` / `PARTIAL` / `WEAK` / `NOT_ALIGNED`,
mark-weighted overall %). It is **not** a grade and **not** correctness; it never writes to marks, feedback, rubrics or answers.
Rubric version, answer fingerprint, model name/version and analysis method are stored per run; stale results are flagged.

| Method | Route | Purpose |
| :--- | :--- | :--- |
| POST | `/api/student-answers/{answer}/rubric-alignment` | Queue an analysis (202). Idempotent while pending; 409 if a fresh completed analysis exists. 422 without an approved rubric / image-only answer. |
| GET | `/api/student-answers/{answer}/rubric-alignment` | Current analysis with criterion alignments, evidence, missing elements, explanation, staleness. |
| GET | `/api/student-answers/{answer}/rubric-alignment/history` | All runs, newest first. |
| POST | `/api/rubric-alignments/{alignment}/regenerate` | New run (202); previous runs preserved. |
| POST | `/api/rubric-alignments/{alignment}/review` | Faculty marks the analysis as reviewed (marks unchanged). |

E2E: `bash backend/tests/e2e_alignment.sh`.

### STEP 30: Student Performance / Gap Analysis

Deterministic Laravel/MySQL aggregation of **finalized faculty marks only** (an answer counts when it is `REVIEWED` with awarded marks and its
submission's `grading_status` is `FACULTY_REVIEWED`/`FINALIZED`; AI-suggested marks are never counted). Percentages are mark-weighted:
`Σ final marks ÷ Σ maximum marks × 100`. Statuses `STRONG / ON_TARGET / MINOR_GAP / MODERATE_GAP / HIGH_GAP / INSUFFICIENT_DATA` are review
signals — no causal claims, no student labels, no grade changes. Thresholds live in `config/performance.php` (`PERFORMANCE_EXPECTED_PERCENT=70`,
`PERFORMANCE_GAP_LOW/MODERATE/HIGH=5/10/20`, `PERFORMANCE_MIN_RESPONSES=5`, `PERFORMANCE_ASYNC_THRESHOLD=500`) and are initial values institutions should calibrate.

| Method | Route | Purpose |
| :--- | :--- | :--- |
| GET | `/api/assessments/{assessment}/performance` | Current snapshot (questions, topics, learning outcomes, gap/strong areas, staleness) or `data: null`. |
| POST | `/api/assessments/{assessment}/performance/analyze` | Generate a snapshot (200 inline; 202 + `AnalyzeStudentPerformanceJob` above the async threshold). 409 if a fresh snapshot exists (`?force=1` to regenerate; history preserved). |
| GET | `/api/assessments/{assessment}/performance/{questions,topics,learning-outcomes,history}` | Sub-resources (cached; invalidated on grade/question changes). |
| GET | `/api/students/{student}/assessments/{assessment}/performance` | Authorized per-student view ("areas for review"; unfinalized marks flagged). |

Topics come from STEP 10 `ai_topics`; LO mapping uses the question's faculty LO plus STEP 11 `STRONG_ALIGNMENT`. STEP 27/28 averages are attached as context only.
The PDF report gains a "Student Performance Summary" section when a completed snapshot exists.

### STEP 31: CO/PO Mapping Validator

Existing `learning_outcomes` act as Course Outcomes (displayed `LO1` → `CO1`). New tables: `programs`, `program_outcomes`, `courses.program_id`,
`co_po_mappings` (level 0–3), `question_co_mappings` (faculty confirm/reject of STEP 11 AI suggestions), `co_po_mapping_analysis_runs`, `co_po_mapping_findings`.
Formulas: CO coverage = marks attributed to CO ÷ total course assessment marks (shared equally when a question maps to several COs); density = non-zero cells ÷ (COs × POs);
PO contribution = Σ(CO coverage × weight 1/3, 2/3, 1); CO/PO student performance reuses STEP 30 finalized marks. Thresholds in `config/co_po.php`
(`COPO_CO_MIN_COVERAGE_PERCENT=5`, `COPO_CO_CONCENTRATION_PERCENT=60`, `COPO_PO_EVIDENCE_MIN_PERCENT=10`, `COPO_MAPPING_DENSITY_REVIEW_PERCENT=90`).
Findings carry STEP 14-style `category/priority/recommendation`. **No accreditation claims are made; AI never creates official mappings.**

| Method | Route | Purpose |
| :--- | :--- | :--- |
| CRUD | `/api/programs`, `/api/programs/{program}/outcomes`, `/api/program-outcomes/{po}` | Programs and institution-defined POs (faculty-scoped). |
| GET | `/api/courses/{course}/co-po-mapping` | Overview: COs, POs, mappings, live summary, current run. |
| POST | `/api/courses/{course}/co-po-mapping/analyze` | Deterministic analysis (409 if unchanged; `?force=1`). Old runs become non-current; mapping changes mark runs `STALE`. |
| GET | `/api/courses/{course}/co-po-mapping/{matrix,findings,co-performance,po-evidence,question-mappings}` | Matrix, findings, CO coverage+performance, PO evidence, question→CO review list. |
| POST/PUT/DELETE | `/api/courses/{course}/co-po-mappings`, `/api/co-po-mappings/{mapping}` | CO→PO mapping (validates LO ∈ course, PO ∈ course's program). |
| POST | `/api/questions/{question}/co-mappings/{confirm,reject}` | Faculty decision on a question→CO suggestion (`mapping_source=FACULTY`). |

E2E for STEP 30 + 31: `bash backend/tests/e2e_performance_copo.sh`.

---

## 3. Development Credentials (Local Only)

> [!WARNING]
> These credentials are strictly configured for local Docker development environments.

* **Database Name**: acultylens
* **MySQL Username**: 
oot
* **MySQL Password**: 
oot123
* **MySQL Host (Inside Docker / Laravel)**: mysql
* **MySQL Port (Inside Docker / Laravel)**: 3306
* **MySQL Host (From Host Machine / GUI Client)**: 127.0.0.1
* **MySQL Port (From Host Machine / GUI Client)**: 3307

---

## 4. Quick Start Commands

### Start All Services
`ash
docker compose up -d --build
`

### View Service Status
`ash
docker compose ps
`

### View Real-time Logs
`ash
# All services
docker compose logs -f

# Laravel App only
docker compose logs -f app

# MySQL only
docker compose logs -f mysql

# phpMyAdmin only
docker compose logs -f phpmyadmin
`

### Run Database Migrations
`ash
docker compose exec app php artisan migrate
`

### Stop All Services
`ash
docker compose down
`

> [!CAUTION]
> Do **NOT** use docker compose down -v during standard development, as it will destroy the persistent MySQL volume (mysql_data).

---

## 5. API Endpoints

### Health Check Endpoint
`http
GET /api/health
Host: http://127.0.0.1:8080/api/health
`

#### Example Response:
`json
{
  status: ok,
  message: FacultyLens API is running,
  service: Laravel Backend,
  database: connected,
  timestamp: 2026-09-07T11:55:00+00:00
}
`

---

## 6. Common Artisan Commands via Docker

`ash
# List all registered routes
docker compose exec app php artisan route:list

# Clear application cache
docker compose exec app php artisan cache:clear

# Clear configuration cache
docker compose exec app php artisan config:clear

# View Laravel system information
docker compose exec app php artisan about
`

---

## 7. phpMyAdmin Access

Open your browser and navigate to:
`	ext
http://127.0.0.1:8081
`

* **Server**: mysql
* **Username**: 
oot
* **Password**: 
oot123
