#  FacultyLens

> **AI-Powered Academic Decision Support for University Faculty**

FacultyLens is an AI-powered academic assistant designed to help university faculty members **understand, analyze, evaluate, and improve their academic work**.

Instead of simply generating academic content, FacultyLens uses AI to provide **intelligent analysis, comparisons, explanations, and actionable recommendations** that help faculty make better academic decisions.

> **FacultyLens doesn't replace faculty expertise. It gives faculty a clearer lens through which to make better decisions.**

---

##  Problem Statement

University faculty members handle many responsibilities beyond classroom teaching.

They may need to:

* Design courses and syllabi
* Align course content with learning outcomes
* Prepare examination questions
* Check assessment coverage
* Detect repeated or similar questions
* Evaluate question diversity and difficulty
* Maintain assessment quality
* Review academic documents
* Improve academic processes

These tasks require significant time, attention, and academic expertise.

FacultyLens addresses this challenge by using AI to **analyze academic materials and provide meaningful insights**.

---

#  What is FacultyLens?

FacultyLens acts as an **AI-powered academic decision-support system**.

A faculty member provides academic materials:

```text
Syllabus
   +
Learning Outcomes
   +
Question Paper
   +
Previous Questions
```

FacultyLens intelligently processes these materials and produces:

```text
AI Analysis
     ↓
Academic Insights
     ↓
Potential Issues
     ↓
Recommendations
```

The faculty member then reviews the results and makes the final decision.

---

#  Core MVP

The initial version of FacultyLens focuses on **AI-powered assessment quality analysis**.

Faculty members can upload:

* Course syllabus
* Learning outcomes
* Current examination paper
* Previous examination papers
* Question bank

FacultyLens analyzes the materials and provides:

* Topic coverage
* Learning-outcome alignment
* Question similarity
* Difficulty distribution
* Cognitive-level distribution
* Assessment quality insights
* AI-generated recommendations

---

#   Key Features

##  1. Syllabus Analysis

FacultyLens analyzes course syllabi to identify:

* Major course topics
* Learning objectives
* Topic distribution
* Potential content gaps
* Overlapping topics
* Course structure

---

##  2. Learning Outcome Alignment

FacultyLens connects:

```text
Learning Outcomes
        ↓
Course Topics
        ↓
Assessment Questions
```

The AI determines whether the examination adequately evaluates the intended learning outcomes.

### Example

```text
Learning Outcome:
Students will be able to analyze database
normalization problems.

Assessment Analysis:

Question 1 → Remember
Question 2 → Understand
Question 3 → Analyze

AI Insight:
⚠ Analytical skills receive limited assessment.

Recommendation:
Consider adding more scenario-based questions.
```

---

##  3. Question Paper Analysis

FacultyLens evaluates examination questions based on:

* Topic coverage
* Learning-outcome coverage
* Question diversity
* Difficulty
* Cognitive level
* Marks distribution
* Question similarity
* Repetition risk

---

##  4. Similar Question Detection

FacultyLens compares current questions against previous assessments and question banks.

Questions can be categorized as:

```text
Exact Match
     ↓
Highly Similar
     ↓
Conceptually Similar
     ↓
Unique
```

This helps faculty identify accidental repetition.

---

##  5. Difficulty Analysis

FacultyLens estimates the difficulty level of questions and provides an overall distribution.

Example:

```text
Easy          30%
Medium        50%
Hard          20%
```

This helps faculty determine whether an assessment has an appropriate difficulty balance.

---

##  6. Cognitive-Level Analysis

Questions can be analyzed according to cognitive complexity:

| Level      | Description           |
| ---------- | --------------------- |
| Remember   | Recall information    |
| Understand | Explain concepts      |
| Apply      | Apply knowledge       |
| Analyze    | Analyze situations    |
| Evaluate   | Make judgments        |
| Create     | Develop new solutions |

Example:

```text
Remember       20%
Understand     25%
Apply          30%
Analyze        15%
Evaluate       10%
Create          0%
```

Faculty can use this information to determine whether an examination appropriately measures higher-order thinking.

---

#  7. Explainable AI Recommendations

FacultyLens doesn't only say that a problem exists.

It explains **why** the problem was identified and suggests possible improvements.

### Example

> **Finding:** A large portion of the examination focuses on basic database concepts.

> **Recommendation:** Consider increasing questions related to SQL optimization and transaction management to improve assessment coverage.

This makes FacultyLens a **decision-support tool rather than a simple content generator**.

---

#  8. AI Chat with Academic Documents (RAG)

Faculty can ask natural-language questions about their own uploaded syllabi, lecture notes, and
question papers and receive **document-grounded answers with source citations**.

```text
React chat UI ──► Laravel (auth + retrieval) ──► FastAPI (grounded answer) ──► Laravel (persist) ──► UI
                        │                                  │
                        │  1. authorize scope (owner only) │  5. prompt = SYSTEM + UNTRUSTED CONTEXT + QUESTION
                        │  2. embed question (MiniLM)      │  6. generate (HF model) or extractive fallback
                        │  3. cosine top-K over indexed    │  7. validate: cites [S#], no leaked instructions
                        │     chunks, threshold            │  8. grounded=true only when context was used
                        │  4. send ONLY authorized chunks  │
```

**Indexing.** When a document finishes text extraction, `GenerateDocumentEmbeddingsJob` splits it into
overlapping word-based chunks (`CHAT_CHUNK_SIZE` / `CHAT_CHUNK_OVERLAP`), embeds them with MiniLM via
`POST /api/v1/embeddings/batch`, and stores them in `document_chunks` (packed float32 BLOBs — MySQL 8.0
has no vector type, so cosine similarity runs application-side). A content hash prevents re-embedding
unchanged documents. Indexing state is tracked per document: `NOT_INDEXED → INDEXING → INDEXED | FAILED`
(`STALE` after reprocessing).

**Two models, two jobs.** `HF_MODEL_NAME` (MiniLM) is an *embedding* model and only ranks chunks. Answer
text comes from a separate `HF_GENERATION_MODEL` (e.g. `google/flan-t5-base`). If unset, a deterministic
**extractive engine** quotes the most relevant retrieved sentences — still grounded, still cited.

**Grounding & safety.**
- Retrieval is restricted to documents the user owns *before* similarity is computed; cross-user and
  cross-course chunks can never become candidates. Scopes: `COURSE`, `DOCUMENT`, `ASSESSMENT`.
- Document text is passed to the model as **untrusted data**; instructions found inside documents are ignored.
- If no chunk clears `CHAT_MIN_RELEVANCE_SCORE`, the assistant replies
  *"I couldn't find enough information about that in the documents available to this chat."* without calling the generator.
- Sources list only real metadata (document name, page, section when known) — never fabricated pages or file paths.
- Every answer carries a disclaimer; `CHAT_RATE_LIMIT` (30/min) throttles messages; sessions/messages are audit-logged.
- A failed AI call persists nothing — the user's question is not saved and can be retried.

**Endpoints.** `GET|POST /api/academic-chat/sessions`, `GET|DELETE /api/academic-chat/sessions/{id}`,
`POST /api/academic-chat/sessions/{id}/messages`. UI at `/academic-chat` and `/courses/:courseId/chat`.

**Limitations.** Retrieval quality depends on extraction quality (scanned PDFs without text are not indexed);
page numbers are available for PDFs only; similarity thresholds are engineering defaults, not correctness
guarantees; small generation models may still paraphrase imprecisely — always verify against the source.

---

#  9. Constrained Question Generator

Faculty generate **draft** assessment questions under explicit academic constraints — never random AI questions,
never auto-published.

```text
Constraints (course · CO · PO · topic · type · difficulty · Bloom · marks · count · document scope)
        ↓
STEP 32 retrieval → relevant document passages          existing questions (assessment, question bank, previous papers)
        ↓                                                          ↓
Hugging Face generation model (HF_GENERATION_MODEL) — or constraint-driven template engine when none is configured
        ↓
Validation per draft: STEP 10 type/difficulty/Bloom · STEP 11 CO alignment (≥0.70 strong / ≥0.50 weak) ·
                      STEP 12 similarity (≥0.85 potential duplicate / ≥0.70 highly similar) · topic · marks
        ↓
Faculty review: Edit (original preserved, versioned) · Approve · Reject · Regenerate (with feedback, limited)
        ↓
[Add to Assessment] — only for APPROVED drafts, only on explicit confirmation → official `questions` row
```

- **Modes.** Standalone drafts for a course, or drafts for a specific assessment (existing questions are supplied
  as "do not reproduce" context; requested marks are checked against the remaining allocation and *warned*, never
  auto-adjusted). Optional **blueprint** (e.g. 2 easy/Remember + 1 hard/Evaluate) with distribution feedback.
- **Validation is advisory.** Requested vs AI-estimated values are shown side by side (`PASSED`,
  `PASSED_WITH_WARNINGS`, `FAILED`); similarity is a retrieval metric, not proof of duplication; warnings are never hidden.
- **Models.** `HF_MODEL_NAME` (MiniLM) is used only for embeddings (alignment/similarity). Drafting uses
  `HF_GENERATION_MODEL`; with none configured, a deterministic template engine drafts from the topic, CO and
  retrieved sentences (reported as `facultylens-constrained-question-template-engine`). Model + prompt versions are stored.
- **Security.** Course/assessment/CO/PO/document ownership is verified server-side before any generation; retrieved
  document text and existing questions are passed to the model as untrusted data; `QUESTION_GENERATION_RATE_LIMIT`
  (10/min) throttles requests; all steps are audit-logged (`QUESTION_GENERATION_REQUESTED` … `QUESTION_ADDED_TO_ASSESSMENT`).
- **Boundaries.** The generator never grades, never modifies existing questions/marks/CO-PO mappings, never creates
  rubrics (use STEP 25 after approval) and never claims academic correctness.

**Endpoints.** `GET|POST /api/question-generation`, `GET /api/question-generation/{id}[/questions]`,
`POST /api/question-generation/{id}/regenerate`, `PUT /api/generated-questions/{id}`,
`POST /api/generated-questions/{id}/{approve|reject|regenerate|add-to-assessment}`.
UI at `/question-generator` and `/courses/:courseId/question-generator`.

---

#  10. Faculty Collaboration

FacultyLens supports **controlled** faculty collaboration — not an open shared workspace.

```text
Faculty → Course → Course Collaboration → Collaborators → Roles → Shared academic resources
```

- **Course-level membership.** `courses.user_id` stays the single OWNER; additional members live in `course_collaborators`
  with roles **EDITOR / REVIEWER / VIEWER**. A faculty member never gains access to a course they were not invited to.
- **Permission matrix** (`config/collaboration.php`) is the single source of truth, enforced server-side through
  `CourseAccessService` and every policy. Editors edit content/assessments/questions and approve recommendations, generated
  questions and rubrics; Reviewers view and comment; Viewers only view. Only the Owner manages collaborators or deletes.
  **Student answers, grades and grading feedback are visible to Owner/Editor only.**
- **Invitations** are single-use, expire (`COLLABORATION_INVITATION_EXPIRES_DAYS`), are stored as SHA-256 hashes, and expose
  only course code/name, inviter and role until accepted. Delivered by mail + in-app notification (queued).
- **Discussion** threads (`collaboration_comments`) on courses, assessments, questions, analysis reports, recommendations,
  generated questions, rubrics and documents: replies, edit-own, resolve/reopen, member-only @mentions, soft delete.
  Comments never modify AI results or official content; decisions still go through the normal approval actions.
- **Activity feed** reuses the audit log (`audit_logs.course_id`), paginated per course; grading events are hidden from
  Reviewers/Viewers. **Version-aware editing**: stale edits of assessments (`expected_updated_at`) and generated
  questions (`expected_version`) return `409` instead of overwriting a colleague's change.
- **Protection.** Rate limits (`COLLABORATION_INVITE_RATE_LIMIT`/hour, `COLLABORATION_COMMENT_RATE_LIMIT`/min), duplicate-
  invitation and duplicate-comment guards, owner cannot be removed or demoted, all actions audited
  (`COLLABORATOR_INVITED`, `COLLABORATION_ACCEPTED`, `COLLABORATOR_ROLE_CHANGED`, `COMMENT_CREATED`, …).

> Collaboration provides controlled faculty review and discussion. AI-generated findings and recommendations remain
> assistive and do not become institutional decisions automatically.

**Endpoints.** `GET /api/courses/{course}/collaboration`, `GET /api/courses/{course}/collaborators`,
`POST /api/courses/{course}/collaborators/invite`, `PATCH|DELETE /api/courses/{course}/collaborators/{user}[/role]`,
`GET /api/collaboration/invitations[/{token}]`, `POST /api/collaboration/invitations/{token}/{accept|decline}`,
`GET|POST /api/courses/{course}/comments`, `PUT|DELETE /api/comments/{id}`, `POST /api/comments/{id}/{resolve|reopen}`,
`GET /api/courses/{course}/collaboration/activity`, `GET /api/collaboration/summary`, `GET /api/notifications`.
UI at `/courses/:courseId/collaboration`, `/collaboration/invitations`, `/collaboration/invitations/:token`.

---

#  FacultyLens Workflow

```text
┌─────────────────────┐
│ Faculty uploads     │
│ academic materials  │
└──────────┬──────────┘
           ↓
┌─────────────────────┐
│ Document Processing │
└──────────┬──────────┘
           ↓
┌─────────────────────┐
│ AI Analysis         │
│                     │
│ • Coverage          │
│ • Alignment         │
│ • Similarity        │
│ • Difficulty        │
│ • Cognitive Level   │
└──────────┬──────────┘
           ↓
┌─────────────────────┐
│ Academic Insights   │
└──────────┬──────────┘
           ↓
┌─────────────────────┐
│ Recommendations     │
└──────────┬──────────┘
           ↓
┌─────────────────────┐
│ Faculty Decision    │
└─────────────────────┘
```

---

#  System Architecture

```text
                     Faculty
                        │
                        ▼
              ┌──────────────────┐
              │ FacultyLens Web  │
              │    Interface     │
              └────────┬─────────┘
                       │
                       ▼
              ┌──────────────────┐
              │    Backend API   │
              └────────┬─────────┘
                       │
          ┌────────────┼────────────┐
          ▼            ▼            ▼
    ┌──────────┐ ┌───────────┐ ┌──────────┐
    │ Document │ │ AI / NLP  │ │ Database │
    │ Processor│ │  Engine   │ │          │
    └──────────┘ └─────┬─────┘ └──────────┘
                       │
                       ▼
              ┌──────────────────┐
              │ Academic Analysis│
              └────────┬─────────┘
                       │
                       ▼
              ┌──────────────────┐
              │ Insights &       │
              │ Recommendations  │
              └──────────────────┘
```

---

#  Technology Stack & Architecture

### Application Architecture

```text
React Frontend (Port 5173)
       ↓
Laravel Backend (Port 8080)
       ↓ HTTP / JSON
Python FastAPI AI Service (Port 8001)
       ↓
Hugging Face NLP Models (all-MiniLM-L6-v2)
       ↓
NLP Processing Pipeline (Segmentation, Questions, Embeddings)
       ↓
Structured JSON Result
       ↓
Laravel Backend
       ↓
React Frontend
```

### Frontend

* React 18 + TypeScript
* Vite
* Tailwind CSS + Lucide Icons
* Axios + TanStack Query

### Backend

* Laravel 12 (PHP 8.2+)
* Laravel Sanctum (Token Authentication)
* MySQL 8.0
* Smalot PDF Parser & PHPWord

### AI Layer (Hugging Face Microservice)

* Python 3.11+
* FastAPI & Uvicorn
* Pydantic v2
* Hugging Face `transformers`, `sentence-transformers`, `torch`
* Model: `sentence-transformers/all-MiniLM-L6-v2` (CPU-optimized, 384 dimensions)
* NLP Pipeline: Text cleaning, paragraph/sentence splitting, question detection, semantic embedding generation
* **Question Analysis (STEP 10)**:
  * Structural & NLP Question Classification (MCQ, True/False, Short Answer, Descriptive, Problem Solving, Conceptual, Analytical)
  * Semantic Topic Matching (Cosine similarity against course syllabus topics)
  * Difficulty Balance Analysis (Easy, Medium, Hard baseline estimation)
  * Bloom's Revised Taxonomy Cognitive Analysis (Remember, Understand, Apply, Analyze, Evaluate, Create)
  * Single & Batch Question Evaluation with Database Persistence (`ai_*` columns)

### Infrastructure

* Docker & Docker Compose
* Multi-container setup (`frontend`, `facultylens-app`, `facultylens-mysql`, `facultylens-phpmyadmin`, `facultylens-ai-service`)

---

#  Project Structure

```text
FacultyLens/
│
├── frontend/
│   ├── src/
│   │   ├── components/
│   │   ├── pages/
│   │   ├── services/
│   │   ├── hooks/
│   │   └── types/
│   └── package.json
│
├── backend/
│   ├── app/
│   │   ├── Http/Controllers/Api/
│   │   ├── Models/
│   │   └── Services/
│   │       ├── AiService.php
│   │       ├── DocumentTextCleaner.php
│   │       └── DocumentTextExtractor.php
│   ├── routes/api.php
│   ├── database/
│   └── composer.json
│
├── ai-service/
│   ├── app/
│   │   ├── main.py
│   │   ├── config.py
│   │   ├── api/routes.py
│   │   ├── schemas/analysis.py
│   │   ├── services/
│   │   │   ├── huggingface_service.py
│   │   │   ├── nlp_pipeline.py
│   │   │   ├── text_cleaner.py
│   │   │   └── analyzer.py
│   │   └── utils/text_utils.py
│   ├── tests/
│   ├── requirements.txt
│   ├── Dockerfile
│   └── README.md
│
├── docker-compose.yml
└── README.md
```

---

#  Example FacultyLens Result

## Assessment Quality Score

```text
        82 / 100
```

| Category                   | Score |
| -------------------------- | ----: |
| Topic Coverage             |   88% |
| Learning Outcome Alignment |   84% |
| Question Diversity         |   79% |
| Difficulty Balance         |   76% |
| Repetition Risk            |   91% |
| Cognitive Diversity        |   74% |

### AI Findings

 **Strong:** Most major course topics are represented.

 **Attention:** The assessment contains a high concentration of medium-difficulty questions.

 **Attention:** Learning Outcome 4 has limited assessment coverage.

 **Potential Issue:** Question 6 is highly similar to a previous examination question.

### Recommendation

> Consider replacing Question 6 with a scenario-based question that evaluates the analytical component of Learning Outcome 4.

---

#  Why FacultyLens?

Traditional AI academic tools often focus on:

```text
Generate
   ↓
Generate
   ↓
Generate
```

FacultyLens focuses on:

```text
Understand
    ↓
Analyze
    ↓
Compare
    ↓
Evaluate
    ↓
Explain
    ↓
Recommend
```

The goal is to help faculty **make better decisions**, not simply generate more content.

---

#  Human-in-the-Loop

FacultyLens is designed around human expertise.

```text
AI Analysis
     ↓
AI Recommendation
     ↓
Faculty Review
     ↓
Faculty Decision
```

AI provides suggestions.

**The faculty member remains responsible for the final academic decision.**

---

#  Privacy & Academic Integrity

FacultyLens should protect academic materials through:

* Secure authentication
* Role-based access
* Controlled document storage
* Secure API communication
* Minimal data retention
* Protection of examination materials
* Controlled access to previous assessments

AI-generated results should be considered **recommendations**, not authoritative academic decisions.

---

#  MVP Scope

The core FacultyLens workflow is:

```text
Upload Materials
       ↓
AI Processing
       ↓
Assessment Analysis
       ↓
Coverage Analysis
       ↓
Learning Outcome Alignment
       ↓
Similarity Detection
       ↓
Difficulty Analysis
       ↓
AI Recommendations
```

The MVP intentionally focuses on **one useful academic journey** rather than attempting to build an entire university management platform.

---

#  Future Scope

FacultyLens can be extended with:

* AI-assisted syllabus design
* Course mapping
* Question paper generation
* Rubric generation
* AI-assisted grading
* Grading consistency analysis
* Research-paper summarization
* Literature discovery
* Faculty workload assistance
* Academic document comparison
* Course quality tracking
* Historical assessment analytics
* Collaborative faculty review

---

#  Expected Impact

FacultyLens can help faculty members:

*  Save time reviewing academic materials
*  Identify assessment gaps
*  Improve course alignment
*  Detect repeated questions
*  Create better-balanced assessments
*  Make evidence-informed decisions
*  Focus more time on teaching and research

---

#  Getting Started

## Clone the Repository

```bash
git clone <your-repository-url>
cd FacultyLens
```

## Start the Backend

```bash
cd backend
cp .env.example .env
```

Configure the required environment variables and start the backend server.

---

## Start the AI Service

```bash
cd ai-service

python -m venv venv
```

### Windows

```bash
venv\Scripts\activate
```

### Linux / macOS

```bash
source venv/bin/activate
```

Install dependencies:

```bash
pip install -r requirements.txt
```

Run the AI service:

```bash
python main.py
```

---

## Start the Frontend

```bash
cd frontend
npm install
npm run dev
```

---

#  System Demonstration

A typical FacultyLens demonstration:

```text
1. Faculty opens FacultyLens
              ↓
2. Uploads syllabus
              ↓
3. Uploads learning outcomes
              ↓
4. Uploads current question paper
              ↓
5. Uploads previous questions
              ↓
6. Clicks "Analyze Assessment"
              ↓
7. AI processes the materials
              ↓
8. FacultyLens generates analysis
              ↓
9. Dashboard displays insights
              ↓
10. Faculty reviews recommendations
```

This demonstrates the complete journey:

> **Problem → Input → AI → Analysis → Useful Result → Faculty Decision**

---

#  Project Goal

> **FacultyLens aims to make academic work easier, smarter, and more evidence-driven by giving university faculty an intelligent lens for understanding and improving their academic processes.**

---

##  Team

**Project Name:** FacultyLens

**Domain:** AI for Academic Life

**Institution:** Ahsanullah University of Science and Technology (AUST)

**Team Name:** `<YOUR TEAM NAME>`

---

##  License

This project is developed as an academic AI system for educational and demonstration purposes.

---

#  FacultyLens

```text
              FACULTY
                 │
                 ▼
          ┌─────────────┐
          │ FacultyLens │
          └──────┬──────┘
                 │
       ┌─────────┼─────────┐
       ▼         ▼         ▼
    Analyze   Compare   Evaluate
       │         │         │
       └─────────┼─────────┘
                 ▼
            Recommend
                 │
                 ▼
        Better Decisions
```

> **See academic work through a smarter lens.**
