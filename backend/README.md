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
