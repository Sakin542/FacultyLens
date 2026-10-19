<div align="center">

<img src="docs/images/logo.png" alt="FacultyLens logo" width="110" />

# FacultyLens

<h3>AI-powered academic decision support for university faculty</h3>

<p><b>Analyze assessments · Align them with learning outcomes · Detect repeated questions · Chat with your course documents</b></p>

<p>
  <img src="https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white" alt="Laravel 12" />
  <img src="https://img.shields.io/badge/React-18-61DAFB?logo=react&logoColor=black" alt="React 18" />
  <img src="https://img.shields.io/badge/TypeScript-5-3178C6?logo=typescript&logoColor=white" alt="TypeScript 5" />
  <img src="https://img.shields.io/badge/FastAPI-Python%203.11-009688?logo=fastapi&logoColor=white" alt="FastAPI" />
  <img src="https://img.shields.io/badge/Hugging%20Face-MiniLM-FFD21E?logo=huggingface&logoColor=black" alt="Hugging Face" />
  <img src="https://img.shields.io/badge/MySQL-8.0-4479A1?logo=mysql&logoColor=white" alt="MySQL 8" />
  <img src="https://img.shields.io/badge/Docker-Compose-2496ED?logo=docker&logoColor=white" alt="Docker Compose" />
</p>

<p>
  <a href="#key-features">Features</a> ·
  <a href="#see-it-in-action">Screenshots</a> ·
  <a href="#how-it-works">How it works</a> ·
  <a href="#getting-started">Getting started</a> ·
  <a href="#testing">Testing</a> ·
  <a href="#documentation">Docs</a>
</p>

> **FacultyLens doesn't replace faculty expertise. It gives faculty a clearer lens for better decisions.**
> AI assists. Faculty decides.

</div>

---

## The problem

Faculty spend hours on work that sits outside the classroom: designing syllabi, aligning content with learning outcomes, writing exam questions, checking coverage and difficulty, and making sure questions aren't repeated from previous years.

Most AI tools answer this with *more generated content*. FacultyLens takes a different path: it **analyzes** what faculty already have, **explains** what it finds, and **recommends** improvements, while every final decision stays with the faculty member.

```text
Typical AI tools:   Generate → Generate → Generate
FacultyLens:        Understand → Analyze → Compare → Evaluate → Explain → Recommend
```

---

## Key features

<table>
<tr>
<td width="50%" valign="top">

### Assessment quality analysis

An overall quality score with six dimensions: topic coverage, learning-outcome coverage, difficulty balance, cognitive diversity, question diversity and marks distribution.

</td>
<td width="50%" valign="top">

### Learning-outcome alignment

Semantic mapping of every question to course learning outcomes (CLOs) and CO/PO mapping, with strong, weak and not-aligned bands.

</td>
</tr>
<tr>
<td valign="top">

### Similar-question detection

Compares new questions against previous papers and the question bank: potential duplicate, highly similar, conceptually similar or unique.

</td>
<td valign="top">

### Bloom's taxonomy & difficulty

Classifies question type, difficulty and cognitive level (Remember → Create) and compares the distribution with your target profile.

</td>
</tr>
<tr>
<td valign="top">

### Chat with your documents

Ask questions about your syllabi, lecture slides and papers. Answers are grounded in your documents, with source citations. Rename and export chats.

</td>
<td valign="top">

### Constrained question generator

Draft questions under explicit constraints (CO, topic, type, difficulty, Bloom, marks). Drafts are never published until you approve them.

</td>
</tr>
<tr>
<td valign="top">

### Blueprints & versioning

Plan an assessment before writing it, validate questions against the blueprint, and keep immutable versions with side-by-side comparison.

</td>
<td valign="top">

### Rubrics & AI grading assistance

Generate rubric drafts, get AI grading *suggestions* with criterion-level evidence, and always record the faculty member's own final mark.

</td>
</tr>
<tr>
<td valign="top">

### Analytics & institutional reports

Course and assessment analytics, student performance and learning gaps, plus PDF / CSV / XLSX reports that never expose student identities.

</td>
<td valign="top">

### Collaboration & notifications

Invite colleagues as Editor, Reviewer or Viewer, discuss any resource, and get real-time in-app and e-mail notifications.

</td>
</tr>
</table>

Every important AI result carries a **"Why?"** button that shows the evidence, method, confidence (only when one exists) and limitations. You can then accept, reject or override it.

---

## See it in action

<table>
<tr>
<td width="50%"><img src="docs/images/assessment-analysis.png" alt="Assessment quality analysis with an overall score and six dimensions" /><p align="center"><b>Assessment quality analysis</b></p></td>
<td width="50%"><img src="docs/images/question-generator.png" alt="Constrained question generator form for a Bayesian networks topic" /><p align="center"><b>Constrained question generator</b></p></td>
</tr>
<tr>
<td><img src="docs/images/academic-analytics.png" alt="Academic analytics dashboard with KPIs and areas needing attention" /><p align="center"><b>Academic analytics</b></p></td>
<td><img src="docs/images/assessment-blueprint.png" alt="Assessment blueprint validation with completeness indicator" /><p align="center"><b>Assessment blueprint</b></p></td>
</tr>
</table>

<sub>Screenshots use synthetic test data from the faculty acceptance-test walkthrough.</sub>

* Invitations
* Collaboration
* Comments
* Mentions
* Reviews
* Shared workflows
* RBAC

## How it works

```mermaid
flowchart LR
    A[Faculty uploads<br/>syllabus, CLOs, papers,<br/>lecture slides] --> B[Document processing<br/>extraction · cleaning · indexing]
    B --> C[AI analysis<br/>coverage · alignment · similarity<br/>difficulty · Bloom level]
    C --> D[Insights &<br/>recommendations<br/>with evidence]
    D --> E{Faculty review}
    E -->|Accept / Override / Reject| F[Faculty decision]
```

**Human in the loop.** AI produces findings and suggestions. Nothing changes a grade, question, mapping or approval until the faculty member acts on it.

### Example result

| Category | Score |
| --- | ---: |
| Topic coverage | 88% |
| Learning-outcome alignment | 84% |
| Question diversity | 79% |
| Difficulty balance | 76% |
| Repetition risk | 91% |
| Cognitive diversity | 74% |
| **Overall quality** | **82 / 100** |

> **Finding:** Question 6 is highly similar to a previous examination question, and Learning Outcome 4 has limited coverage.
> **Recommendation:** Replace Question 6 with a scenario-based question that assesses the analytical part of Learning Outcome 4.

---

## Architecture

```mermaid
flowchart TB
    UI["React 18 + TypeScript SPA<br/>Vite · Tailwind · :3000"]
    API["Laravel 12 API<br/>Sanctum · Policies · Jobs · :8080"]
    AI["FastAPI AI service<br/>Hugging Face MiniLM · :8001"]
    DB[(" MySQL 8.0")]
    R[(" Redis<br/>queues · cache")]
    H["Horizon workers<br/>extraction · embeddings · analysis · reports"]
    WS["Reverb WebSockets<br/>real-time notifications"]

    UI -->|cookie session + CSRF| API
    UI <-->|live events| WS
    API --> DB
    API --> R
    R --> H
    H --> AI
    API -->|X-AI-Service-Key| AI
    H --> DB
```

| Layer | Technology |
| --- | --- |
| **Frontend** | React 18, TypeScript 5, Vite 6, Tailwind CSS 3, React Router 6, Lucide icons, Laravel Echo + Pusher JS |
| **Backend** | Laravel 12 (PHP 8.2+), Sanctum cookie auth, Horizon, Reverb, MySQL 8.0, Redis 7 |
| **Documents** | Poppler `pdftotext` for PDFs (correct word spacing and math symbols), Smalot PDF Parser fallback, PHPWord for DOCX |
| **AI service** | Python 3.11, FastAPI, Pydantic v2, `sentence-transformers/all-MiniLM-L6-v2` (384-dim embeddings), optional generation model |
| **Testing** | PHPUnit, Vitest + Testing Library, Pytest, Playwright + axe accessibility checks |
| **Infrastructure** | Docker Compose (dev) and a hardened production compose with nginx, php-fpm and a private AI service |

<details>
<summary><b>Project structure</b></summary>

```text
FacultyLens/
├── frontend/                 React SPA
│   ├── src/{components,pages,services,hooks,context,types}
│   └── tests/e2e/            Playwright journeys and faculty UAT walkthroughs
├── backend/                  Laravel API
│   ├── app/{Http/Controllers/Api,Models,Services,Jobs,Policies}
│   ├── routes/api.php
│   ├── database/migrations
│   └── tests/{Feature,Unit}  PHPUnit suite (in-memory SQLite) + e2e shell journeys
├── ai-service/               FastAPI + Hugging Face
│   ├── app/{api,schemas,services}
│   ├── datasets/             evaluation and safety benchmarks
│   └── tests/
├── docs/                     design notes, validation and test reports
├── scripts/                  backup, restore, test and evaluation helpers
├── docker-compose.yml        development stack
└── docker-compose.prod.yml   production stack
```

</details>

---

## Feature deep dives

<details>
<summary><b>AI chat with academic documents (RAG)</b></summary>

Faculty ask natural-language questions about their own syllabi, lecture notes and question papers, and get **document-grounded answers with source citations**.

```text
React chat UI ──► Laravel (auth + retrieval) ──► FastAPI (grounded answer) ──► Laravel (persist) ──► UI
                        │                                  │
                        │  1. authorize scope (owner only) │  5. prompt = SYSTEM + UNTRUSTED CONTEXT + QUESTION
                        │  2. embed question (MiniLM)      │  6. generate (HF model) or extractive fallback
                        │  3. cosine top-K over indexed    │  7. validate: cites [S#], no leaked instructions
                        │     chunks, threshold            │  8. grounded=true only when context was used
                        │  4. send ONLY authorized chunks  │
```

- **Indexing.** When text extraction finishes, `GenerateDocumentEmbeddingsJob` splits the document into overlapping word-based chunks (`CHAT_CHUNK_SIZE` / `CHAT_CHUNK_OVERLAP`), embeds them with MiniLM via `POST /api/v1/embeddings/batch`, and stores them in `document_chunks` as packed float32 BLOBs. MySQL 8.0 has no vector type, so cosine similarity runs in the application. A content hash prevents re-embedding unchanged documents. Indexing state: `NOT_INDEXED → INDEXING → INDEXED | FAILED` (`STALE` after reprocessing).
- **Two models, two jobs.** `HF_MODEL_NAME` (MiniLM) only ranks chunks. Answer text comes from `HF_GENERATION_MODEL` (for example `google/flan-t5-base`). If none is set, a deterministic **extractive engine** answers from the most relevant lines. It understands slide decks: bullet lists under a matching heading, wrapped formulas, and probability expressions written in different notations.
- **Slide-friendly extraction.** PDFs are extracted with `pdftotext` and Symbol-font glyphs are mapped to real Unicode, so formulas such as `P(a ∧ ¬j ∧ m)` survive.
- **Grounding & safety.**
  - Retrieval is restricted to documents the user may access *before* similarity is computed. Scopes are `COURSE`, `DOCUMENT` and `ASSESSMENT`.
  - Document text is passed to the model as **untrusted data**, and instructions found inside documents are ignored.
  - If nothing clears `CHAT_MIN_RELEVANCE_SCORE`, the assistant replies *"I couldn't find enough information about that in the documents available to this chat."*
  - Sources list real metadata only (document name, page, section when known), never fabricated pages or file paths.
  - Every answer carries a disclaimer. `CHAT_RATE_LIMIT` (30/min) throttles messages, and sessions are audit-logged.
  - A failed AI call saves nothing, so the question can simply be retried.
- **Endpoints.** `GET|POST /api/academic-chat/sessions`, `GET|PATCH|DELETE /api/academic-chat/sessions/{id}`, `GET /api/academic-chat/sessions/{id}/export?format=markdown|json`, `POST /api/academic-chat/sessions/{id}/messages`. The UI is at `/academic-chat` and `/courses/:courseId/chat`.
- **Limitations.** Scanned PDFs without a text layer are not indexed. Page numbers exist for PDFs only. Similarity thresholds are engineering defaults, not correctness guarantees. Always verify answers against the cited source.

</details>

<details>
<summary><b>Constrained question generator</b></summary>

Faculty generate **draft** questions under explicit academic constraints. They are never random and never auto-published.

```text
Constraints (course · CO · PO · topic · type · difficulty · Bloom · marks · count · document scope)
        ↓
Document retrieval → relevant passages        Existing questions (assessment, question bank, previous papers)
        ↓                                                ↓
Generation model (HF_GENERATION_MODEL) — or a constraint-driven template engine when none is configured
        ↓
Validation per draft: type/difficulty/Bloom · CO alignment (≥0.70 strong / ≥0.50 weak)
                      · similarity (≥0.85 potential duplicate / ≥0.70 highly similar) · topic · marks
        ↓
Faculty review: Edit (original preserved, versioned) · Approve · Reject · Regenerate (with feedback)
        ↓
[Add to Assessment] — only for APPROVED drafts, only on explicit confirmation
```

- **Modes.** Standalone drafts for a course, or drafts for a specific assessment. Existing questions are supplied as "do not reproduce" context, and requested marks are checked against the remaining allocation and *warned about*, never auto-adjusted.
- **Validation is advisory.** Requested and AI-estimated values are shown side by side (`PASSED`, `PASSED_WITH_WARNINGS`, `FAILED`). Warnings are never hidden.
- **Security.** Course, assessment, CO/PO and document ownership is verified server-side. Retrieved text is untrusted. `QUESTION_GENERATION_RATE_LIMIT` (10/min) throttles requests, and every step is audit-logged.
- **Boundaries.** The generator never grades, never modifies existing questions, marks or mappings, and never claims academic correctness.
- **Endpoints.** `GET|POST /api/question-generation`, `GET /api/question-generation/{id}[/questions]`, `POST /api/question-generation/{id}/regenerate`, `PUT /api/generated-questions/{id}`, `POST /api/generated-questions/{id}/{approve|reject|regenerate|add-to-assessment}`. The UI is at `/question-generator` and `/courses/:courseId/question-generator`.

</details>

<details>
<summary><b>Explainability & transparency</b></summary>

Every important AI result has a **"Why?"** panel with progressive disclosure:

1. **What** FacultyLens produced and **why**, as one evidence-based sentence (never hidden reasoning).
2. **Evidence**: the wording that fired a rule, the actual similarity score (`0.82 / 1.00`, never a probability), threshold bands, LO text, rubric criteria and retrieved passages.
3. **Method & model**: `RULE_BASED`, `EMBEDDING_BASED`, `HYBRID`, `GENERATIVE` or `HUMAN_CONFIRMED`, with engine, prompt and embedding versions.
4. **Confidence**, only when the method produces one. Otherwise it reads "Not available".
5. **Limitations** per component.
6. **Faculty decision**: Accept, Reject, Mark as reviewed or **Override** with a reason. The AI value is kept for reference and every decision is audited.

See [docs/AI_EXPLAINABILITY.md](docs/AI_EXPLAINABILITY.md).

</details>

<details>
<summary><b>Assessment blueprints</b></summary>

Open `/assessments/:assessmentId/blueprint` to plan sections (for example *Section A: MCQ 10 × 1 = 10*), difficulty, Bloom, CO, PO and topic targets, and cross-dimension plan rows (*CO2 · Analyze · Medium · Problem solving · 2 × 5*).

- Deterministic validation reports `VALID`, `VALID_WITH_WARNINGS` or `INVALID`, with CO × difficulty and CO × Bloom matrices.
- **Blueprint Completeness** is a planning indicator, not an assessment-quality score.
- Finalized blueprints are immutable (edits create a new version), feed the question generator, and are compared with the actual question set (`MATCH` / `CLOSE` / `MISMATCH`).
- The blueprint never publishes the assessment or changes questions automatically.

See [docs/assessment-blueprint.md](docs/assessment-blueprint.md).

</details>

<details>
<summary><b>Assessment versioning</b></summary>

Open `/assessments/:assessmentId/versions` to create **v1.0**, a snapshot of the current questions, blueprint and approved rubrics, and follow `DRAFT → IN_REVIEW → APPROVED → FINALIZED`.

- Version numbers (`v1.0`, `v1.1`, `v2.0`) are generated server-side and never reused.
- Finalized, approved and student-referenced versions are immutable. The action is always **Create new version** or **Restore as new version**.
- Comparison is deterministic and reports `MODIFIED` / `ADDED` / `REMOVED` / `UNCHANGED`, marks, CO/Bloom/difficulty changes and quality-metric differences.
- Analyses, reports, analytics and student submissions record the exact `assessment_version_id`.

See [docs/assessment-versioning.md](docs/assessment-versioning.md).

</details>

<details>
<summary><b>Faculty collaboration & notifications</b></summary>

- **Collaboration hub** (`/collaboration`, the sidebar's Collaboration link). In one place you can:
  - accept or decline invitations sent to you;
  - see each of your courses with its team size, pending invitations and open discussions;
  - invite a colleague to any of your courses;
  - open the courses shared with you, with their owner and your role;
  - revoke invitations you sent.
- **How it works end to end:**
  1. The owner invites a colleague by e-mail as Editor, Reviewer or Viewer.
  2. The colleague accepts from their hub or from the e-mailed link.
  3. The course appears in their Courses list marked "Shared · role", with only the actions that role allows.
  4. Discussions, @mentions and the activity feed keep everyone up to date.
  5. The owner can change a role or remove a member at any time, and access ends immediately.
- **Course-level membership.** The course creator is the single OWNER. Collaborators have the role **EDITOR**, **REVIEWER** or **VIEWER**, and nobody gains access to a course they weren't invited to.
- **Permission matrix** (`config/collaboration.php`) is enforced server-side through `CourseAccessService` and every policy. Student answers, grades and grading feedback are visible to Owner and Editor only.
- **Invitations** are single-use, expire, are stored as SHA-256 hashes, and arrive by e-mail and in-app notification.
- **Discussion threads** on courses, assessments, questions, reports, recommendations, rubrics and documents support replies, resolve/reopen and @mentions. Comments never change AI results or official content.
- **Version-aware editing.** Stale edits return `409` instead of overwriting a colleague's change.
- **Notifications.** Every important event creates an informational notification (`event → listener → queued job → notification bell`), delivered in real time over Reverb. Notifications never approve, finalize or grade anything. See [docs/NOTIFICATION_SYSTEM.md](docs/NOTIFICATION_SYSTEM.md).

</details>

<details>
<summary><b>Analytics, AI evaluation & institutional reports</b></summary>

- **Academic analytics** (`/analytics`) aggregates real records (analysis reports, finalized grades, performance runs, CO/PO runs, evaluation runs, audit log) for the courses you can access. Metrics without data show **N/A**, never fabricated zeroes. See [docs/academic-analytics.md](docs/academic-analytics.md).
- **AI evaluation** (`/ai-evaluation`) shows accuracy, macro/weighted F1, confusion matrices, similarity precision/recall, grading MAE/RMSE, RAG groundedness and refusal results, and regression gates. Every number comes from a persisted evaluation run. See [docs/ai-evaluation.md](docs/ai-evaluation.md) and [docs/AI_ACCURACY_EVALUATION_REPORT.md](docs/AI_ACCURACY_EVALUATION_REPORT.md).
- **Institutional reports** (`/reports`) follow `Preview → Validate access → Generate → Export → Download → Audit` in PDF, CSV or XLSX.
  - Scope is decided by the server from the course role matrix, never by the client.
  - Student reports are aggregates of finalized grades only. No names, identifiers or individual answers are exported.
  - Files live on private storage and expire after `REPORTS_EXPIRATION_DAYS` (default 7).
  - See [docs/institutional-reporting.md](docs/institutional-reporting.md).

</details>

```bash
cd backend
composer install
```

## Getting started

### Prerequisites

- Docker Desktop with Docker Compose
- Node.js 20+ and npm, for the frontend dev server
- *(Optional, for running without Docker)* PHP 8.2+, Composer, Python 3.11+

### Quick start with Docker (recommended)

```bash
git clone https://github.com/Sakin542/FacultyLens.git
cd FacultyLens

cp backend/.env.example backend/.env          # dev values; DB_PASSWORD=root123 matches docker-compose.yml
docker compose up -d --build                   # API, MySQL, Redis, Horizon, Reverb, AI service, Mailpit, phpMyAdmin
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed   # synthetic data: faculty@example.com / password123

cd frontend
cp .env.example .env
npm install
npm run dev                                    # http://localhost:3000
```

Check that everything is up:

```bash
curl http://127.0.0.1:8080/api/health          # {"status":"ok", ...}
curl http://127.0.0.1:8080/api/health/ready    # database, cache, storage, queue, ai_service all "ok"
```

### Local services

| Service | URL |
| --- | --- |
| Web app | <http://localhost:3000> |
| Laravel API | <http://127.0.0.1:8080/api> |
| AI service | <http://127.0.0.1:8001/health> |
| Mailpit (dev e-mail inbox) | <http://localhost:8025> |
| phpMyAdmin | <http://localhost:8081> |
| MySQL | `127.0.0.1:3307` |
| Reverb WebSockets | `localhost:8085` |

<details>
<summary><b>Running without Docker</b></summary>

#### Backend

```bash
cd backend
cp .env.example .env
composer install && php artisan key:generate && php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8080
php artisan queue:work --queue=emails,default   # second terminal (or `php artisan horizon` on Linux/macOS)
```

#### AI service

```bash
cd ai-service
python -m venv venv
venv\Scripts\activate            # Windows
source venv/bin/activate         # Linux / macOS
pip install -r requirements.txt
cp .env.example .env             # HF_MODEL_NAME, optional HF_TOKEN, AI_SERVICE_API_KEY (must match backend)
uvicorn app.main:app --host 0.0.0.0 --port 8001
```

The model loads once at startup. `GET /health` is liveness, and `GET /ready` returns 503 until the model is ready.

#### Frontend

```bash
cd frontend
cp .env.example .env             # VITE_API_BASE_URL=http://127.0.0.1:8080/api
npm install
npm run dev
```

</details>

<details>
<summary><b>Environment variables</b></summary>

| File | Purpose |
| --- | --- |
| `backend/.env.example` / `.env.production.example` | Laravel: APP_KEY, DB, Redis, `AI_SERVICE_URL` + `AI_SERVICE_API_KEY`, CORS, Sanctum, session, reports |
| `frontend/.env.example` / `.env.production.example` | `VITE_*` values only. Everything here ships to the browser, so never put secrets in it |
| `ai-service/.env.example` / `.env.production.example` | FastAPI: `HF_MODEL_NAME`, `HF_TOKEN`, `AI_SERVICE_API_KEY`, chat and generation limits |
| `.env.prod.example` | Values for `docker-compose.prod.yml` (DB/Redis passwords, image tag, TLS directory) |

`.env` files are git-ignored. Only the `*.example` templates are committed.

</details>

---

## Testing

```bash
cd backend && php artisan test                 # PHPUnit feature + unit suite (in-memory SQLite only)
cd frontend && npm test && npm run build       # Vitest + strict TypeScript production build
cd ai-service && pytest -q && ruff check .     # FastAPI tests + lint
cd frontend && npm run test:e2e                # Playwright browser journeys + axe accessibility (Docker stack up)
bash backend/tests/e2e_all.sh                  # every end-to-end journey against the Docker stack (real AI)
scripts/test-all.sh [--e2e] [--golden]         # aggregated regression run (also scripts/test-all.ps1)
```

> **Run the PHPUnit suite from the host, not inside the Docker app container.** The suite now refuses to start unless it is connected to the in-memory SQLite database, so it can never touch the development MySQL data.

Validation results live in [docs/E2E_VALIDATION_REPORT.md](docs/E2E_VALIDATION_REPORT.md), [docs/FACULTY_UAT_REPORT.md](docs/FACULTY_UAT_REPORT.md) and [docs/PRODUCTION_READINESS.md](docs/PRODUCTION_READINESS.md).

---

## Security & privacy

- **Sessions.** Sanctum cookie sessions (HttpOnly, `Secure` in production, SameSite=lax), CSRF on every change, and explicit CORS origins.
- **Authorization** happens server-side only, using the course role matrix (`OWNER / EDITOR / REVIEWER / VIEWER`) plus `FACULTY / ADMIN` roles. Every resource id is re-checked.
- **Private storage** for documents and reports, streamed through authenticated endpoints only.
- **Security headers** (CSP, HSTS, nosniff, frame-ancestors, Referrer-Policy) and structured request logs without bodies or tokens.
- **Rate limits** on auth, uploads, AI analysis, chat, generation, collaboration, search and reports.
- **AI safety.** An internal service key, timeouts, safe error messages, and document text always treated as untrusted context.
- **Student privacy.** Reports and analytics are aggregates, and AI never assigns or changes grades, approvals or mappings.

---

## Production & operations

- **Production stack.** `docker-compose.prod.yml` runs **nginx** (TLS, static React, security headers) → **php-fpm** → MySQL, Redis, worker, scheduler, and a private **AI service** with no published ports.
- **Backup & restore.** `scripts/backup.sh` (MySQL dump + private storage + manifest) and `scripts/restore.sh` (verified restore into a scratch database first).
- **Guide.** Release flow, smoke tests, rollback and monitoring are in [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

---

## Documentation

| Topic | Document |
| --- | --- |
| API reference | [docs/API.md](docs/API.md) |
| Deployment & troubleshooting | [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) |
| AI evaluation & accuracy | [docs/ai-evaluation.md](docs/ai-evaluation.md) · [docs/AI_ACCURACY_EVALUATION_REPORT.md](docs/AI_ACCURACY_EVALUATION_REPORT.md) |
| AI safety | [docs/AI_SAFETY_TESTING.md](docs/AI_SAFETY_TESTING.md) · [docs/AI_HALLUCINATION_POLICY.md](docs/AI_HALLUCINATION_POLICY.md) · [docs/AI_PROMPT_INJECTION_DEFENSE.md](docs/AI_PROMPT_INJECTION_DEFENSE.md) |
| Explainability | [docs/AI_EXPLAINABILITY.md](docs/AI_EXPLAINABILITY.md) |
| Analytics & reporting | [docs/academic-analytics.md](docs/academic-analytics.md) · [docs/institutional-reporting.md](docs/institutional-reporting.md) |
| Blueprints & versioning | [docs/assessment-blueprint.md](docs/assessment-blueprint.md) · [docs/assessment-versioning.md](docs/assessment-versioning.md) |
| Notifications & e-mail | [docs/NOTIFICATION_SYSTEM.md](docs/NOTIFICATION_SYSTEM.md) · [docs/REALTIME_NOTIFICATION_SYSTEM.md](docs/REALTIME_NOTIFICATION_SYSTEM.md) · [docs/EMAIL_SYSTEM.md](docs/EMAIL_SYSTEM.md) |
| Testing & quality | [docs/FACULTY_UAT_REPORT.md](docs/FACULTY_UAT_REPORT.md) · [docs/REGRESSION_TEST_MATRIX.md](docs/REGRESSION_TEST_MATRIX.md) · [docs/DATA_QUALITY_INTEGRITY_REPORT.md](docs/DATA_QUALITY_INTEGRITY_REPORT.md) |

---

## Roadmap

- [x] Assessment quality analysis, LO alignment, similarity, difficulty and Bloom analysis
- [x] Document chat (RAG) with citations, rename and export
- [x] Constrained question generator, rubrics and AI grading assistance
- [x] Blueprints, versioning, collaboration, analytics and institutional reports
- [x] Real-time notifications and e-mail
- [] AI-assisted syllabus design and course mapping
- [] Research-paper summarization and literature discovery
- [] Faculty workload assistance
- [] Course quality tracking across semesters

---

## Author

FacultyLens is a personal project built and maintained by [Sakin542](https://github.com/Sakin542).

| | |
| --- | --- |
| **Project** | FacultyLens |
| **Domain** | AI for Academic Life |
| **Institution** | Ahsanullah University of Science and Technology (AUST) |
| **Repository** | [github.com/Sakin542/FacultyLens](https://github.com/Sakin542/FacultyLens) |

## License

```bash
git add .
git commit -m "Add your feature"
git push origin feature/your-feature-name
```

Before opening a pull request:

* Run backend tests
* Run frontend tests
* Run AI tests
* Check TypeScript errors
* Check PHP errors
* Check Python errors
* Verify environment variables
* Verify Docker services
* Review security
* Review academic safety

---

<div align="center">

**FacultyLens** · *See academic work through a smarter lens.*

AI assists · Faculty decides

</div>
