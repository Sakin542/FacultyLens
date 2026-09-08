# FacultyLens AI Service

FastAPI-powered AI and NLP microservice for **FacultyLens**, providing text preprocessing, question segmentation, and Hugging Face Sentence Transformer semantic embeddings.

---

## 1. Architecture

```text
React Frontend
       ↓
Laravel Backend (Port 8080)
       ↓  HTTP / JSON (Internal)
Python FastAPI AI Service (Port 8001)
       ↓
Hugging Face Sentence Transformers Model (CPU)
       ↓
NLP Processing Pipeline
       ↓
Structured JSON Result
       ↓
Laravel Backend
       ↓
React Frontend
```

---

## 2. Technology Stack

* **Language**: Python 3.11+
* **Framework**: FastAPI + Uvicorn
* **Data Validation**: Pydantic v2
* **NLP & Models**: Hugging Face `transformers`, `sentence-transformers`, `torch`
* **Default Model**: `sentence-transformers/all-MiniLM-L6-v2` (384 dimensions, CPU optimized)
* **Testing**: Pytest, HTTPX

---

## 3. Directory Structure

```text
ai-service/
├── app/
│   ├── __init__.py
│   ├── main.py
│   ├── config.py
│   ├── api/
│   │   ├── __init__.py
│   │   └── routes.py
│   ├── schemas/
│   │   ├── __init__.py
│   │   └── analysis.py
│   ├── services/
│   │   ├── __init__.py
│   │   ├── huggingface_service.py
│   │   ├── nlp_pipeline.py
│   │   ├── text_cleaner.py
│   │   └── analyzer.py
│   └── utils/
│       ├── __init__.py
│       └── text_utils.py
├── tests/
│   ├── __init__.py
│   ├── test_health.py
│   ├── test_preprocess.py
│   └── test_analysis.py
├── requirements.txt
├── .env.example
├── .gitignore
├── Dockerfile
└── README.md
```

---

## 4. Environment Variables

Create a `.env` file from `.env.example`:

```bash
cp .env.example .env
```

| Variable | Default | Description |
| :--- | :--- | :--- |
| `APP_NAME` | `FacultyLens AI Service` | Service application name |
| `APP_ENV` | `local` | Environment (`local`, `production`) |
| `AI_SERVICE_HOST` | `0.0.0.0` | Host binding for Uvicorn |
| `AI_SERVICE_PORT` | `8001` | Port binding |
| `HF_MODEL_NAME` | `sentence-transformers/all-MiniLM-L6-v2` | Hugging Face model repository ID |
| `HF_TOKEN` | *(empty)* | Optional Hugging Face access token |
| `HF_HOME` | `./cache/huggingface` | Model cache directory on disk |
| `AI_SERVICE_API_KEY` | *(empty)* | Optional internal API key for request validation (`X-AI-Service-Key`) |
| `RUBRIC_GENERATION_ENABLED` | `false` | STEP 25: enable optional Hugging Face seq2seq model for rubric drafting |
| `RUBRIC_GENERATION_MODEL` | *(empty)* | e.g. `google/flan-t5-small` (~300 MB, ~1 GB RAM, CPU). Empty = template engine only |
| `RUBRIC_GENERATION_MAX_NEW_TOKENS` | `192` | Generation length cap for the rubric model |
| `RUBRIC_MAX_CRITERIA` | `8` | Maximum criteria in a generated rubric draft |

> **Model roles:** `HF_MODEL_NAME` (MiniLM) is an *encoder* used for embeddings, similarity and
> semantic analysis only. It cannot draft rubric text. Rubric drafting uses a deterministic
> template engine and, optionally, a separate small generative model configured above.
> Generated output is always validated (marks must sum to the question total) and the
> template engine is used as a fallback.

---

## 5. Local Setup & Execution

### 5.1 Create Virtual Environment & Install Dependencies

```bash
cd ai-service
python -m venv .venv
# On Windows:
.venv\Scripts\activate
# On Linux/macOS:
source .venv/bin/activate

pip install -r requirements.txt
```

### 5.2 Run the Service

```bash
uvicorn app.main:app --reload --host 0.0.0.0 --port 8001
```

The service will be available at: `http://127.0.0.1:8001`
Interactive API Docs (Swagger): `http://127.0.0.1:8001/docs`

---

## 6. Docker Execution

The AI service is integrated with the main Docker Compose configuration:

```bash
docker compose up -d ai-service
```

Logs can be viewed via:

```bash
docker compose logs -f ai-service
```

---

## 7. API Endpoints

### 7.1 Health Check
`GET /health`

**Response:**
```json
{
  "status": "ok",
  "service": "FacultyLens AI Service",
  "model": "sentence-transformers/all-MiniLM-L6-v2",
  "model_loaded": true
}
```

### 7.2 Preprocess Text
`POST /api/v1/preprocess`

**Request:**
```json
{
  "text": "1. What is polymorphism?\n\n2. Explain inheritance."
}
```

**Response:**
```json
{
  "status": "success",
  "cleaned_text": "1. What is polymorphism?\n\n2. Explain inheritance.",
  "paragraphs": ["1. What is polymorphism?", "2. Explain inheritance."],
  "sentences": ["1. What is polymorphism?", "2. Explain inheritance."]
}
```

### 7.3 Analyze Academic Text
`POST /api/v1/analyze`

**Request:**
```json
{
  "document_type": "question_paper",
  "text": "1. Explain database normalization.\n2. Describe the difference between SQL and NoSQL databases.\n3. Compare relational and non-relational database systems."
}
```

**Response:**
```json
{
  "status": "success",
  "document_type": "question_paper",
  "analysis": {
    "character_count": 164,
    "word_count": 21,
    "sentence_count": 3,
    "paragraph_count": 3,
    "questions_detected": 3,
    "questions": [
      {
        "number": 1,
        "text": "Explain database normalization."
      },
      {
        "number": 2,
        "text": "Describe the difference between SQL and NoSQL databases."
      },
      {
        "number": 3,
        "text": "Compare relational and non-relational database systems."
      }
    ],
    "keywords": ["database", "databases", "relational", "nosql", "sql"],
    "embeddings_generated": true,
    "embedding_dimension": 384,
    "model": "sentence-transformers/all-MiniLM-L6-v2"
  }
}
```

### 7.4 Internal Embedding Generation
`POST /api/v1/embedding`

**Request:**
```json
{
  "text": "Explain database normalization.",
  "return_vector": false
}
```

**Response:**
```json
{
  "status": "success",
  "embedding_dimension": 384,
  "model": "sentence-transformers/all-MiniLM-L6-v2",
  "vector": null
}
```

### 7.5 Single Question Analysis (STEP 10)
`POST /api/v1/analyze-question`

**Request:**
```json
{
  "question": "Explain the differences between 3NF and BCNF in relational database design with suitable examples.",
  "course_topics": ["Normalization", "Relational Design", "SQL", "Transactions"]
}
```

**Response:**
```json
{
  "status": "success",
  "question": "Explain the differences between 3NF and BCNF in relational database design with suitable examples.",
  "classification": {
    "question_type": "DESCRIPTIVE",
    "confidence": 0.85
  },
  "topics": [
    {
      "name": "Normalization",
      "confidence": 0.7241
    },
    {
      "name": "Relational Design",
      "confidence": 0.6892
    }
  ],
  "difficulty": {
    "level": "MEDIUM",
    "method": "baseline",
    "keywords": ["explain", "difference", "example"]
  },
  "cognitive_level": {
    "level": "UNDERSTAND",
    "method": "baseline",
    "detected_action_verbs": ["explain"]
  }
}
```

### 7.6 Batch Questions Analysis (STEP 10)
`POST /api/v1/analyze-questions`

**Request:**
```json
{
  "questions": [
    {
      "number": 1,
      "text": "Define database normalization."
    },
    {
      "number": 2,
      "text": "Design a normalized relational schema for a university management system."
    }
  ],
  "course_topics": ["Normalization", "Relational Design", "SQL"]
}
```

**Response:**
```json
{
  "status": "success",
  "total_questions": 2,
  "questions": [
    {
      "number": 1,
      "question": "Define database normalization.",
      "classification": { "question_type": "CONCEPTUAL", "confidence": 0.85 },
      "topics": [{ "name": "Normalization", "confidence": 0.88 }],
      "difficulty": { "level": "EASY", "method": "baseline" },
      "cognitive_level": { "level": "REMEMBER", "method": "baseline" }
    },
    {
      "number": 2,
      "question": "Design a normalized relational schema for a university management system.",
      "classification": { "question_type": "DESCRIPTIVE", "confidence": 0.8 },
      "topics": [{ "name": "Relational Design", "confidence": 0.89 }],
      "difficulty": { "level": "HARD", "method": "baseline" },
      "cognitive_level": { "level": "CREATE", "method": "baseline" }
    }
  ],
  "summary": {
    "question_types": { "CONCEPTUAL": 1, "DESCRIPTIVE": 1 },
    "difficulty_distribution": { "EASY": 1, "HARD": 1 },
    "cognitive_distribution": { "REMEMBER": 1, "CREATE": 1 },
    "topics_detected": ["Normalization", "Relational Design"]
  }
}
```

### 7.7 AI Rubric Generator (STEP 25)

**POST** `/api/v1/generate-rubric`

Produces a structured **draft** grading rubric for a single question. The draft is an
assistive artifact: Laravel stores it with `status = DRAFT` and faculty must review,
edit and approve it. Criterion marks are guaranteed to sum exactly to `total_marks`.

Request:

```json
{
  "question_id": 15,
  "question_text": "Explain database normalization and describe 1NF, 2NF, and 3NF with examples.",
  "question_type": "DESCRIPTIVE",
  "total_marks": 10,
  "difficulty_level": "MEDIUM",
  "cognitive_level": "UNDERSTAND",
  "learning_outcome": { "code": "CO2", "description": "Explain fundamental database concepts." },
  "course_context": { "course_code": "CSE101", "course_name": "Database Systems" }
}
```

Supported `question_type` values (case-insensitive): `MCQ`, `SHORT_ANSWER`, `DESCRIPTIVE`,
`PROBLEM_SOLVING`, `TRUE_FALSE`, `CONCEPTUAL`, `ANALYTICAL`, `OTHER`.

Response:

```json
{
  "status": "success",
  "generation_method": "template_based",
  "draft_status": "DRAFT",
  "rubric": {
    "title": "Q15 Rubric: Database normalization",
    "question_text": "...",
    "total_marks": 10,
    "criteria": [
      {
        "criterion": "Explanation of database normalization",
        "description": "Correctly explains database normalization as required by the question.",
        "max_marks": 2,
        "scoring_guidance": "Full marks (2) for ... Partial credit (about 1) ... No marks if ...",
        "expected_indicators": ["Database normalization addressed directly", "Key points covered"],
        "sort_order": 1
      }
    ],
    "general_guidance": "Award marks based on demonstrated understanding ... This is an AI-generated draft."
  },
  "metadata": {
    "model": "facultylens-rubric-template-engine",
    "version": "1.0.0",
    "embedding_model": "sentence-transformers/all-MiniLM-L6-v2",
    "generative_model_used": false,
    "criteria_count": 6,
    "validation_passed": true,
    "disclaimer": "AI-generated draft rubric. Faculty review and approval are required before use."
  }
}
```

`generation_method` is `ai_assisted` only when the optional generative model actually
contributed criteria. No confidence score is returned because none is calibrated.

---

## 8. Laravel Integration

The Laravel backend communicates with this service through `App\Services\AiService`.

* **Local dev**: `AI_SERVICE_URL=http://127.0.0.1:8001`
* **Docker container**: `AI_SERVICE_URL=http://ai-service:8001`

Faculty members authenticate with Laravel Sanctum, and make requests to:
* `POST /api/ai/analyze` (Document and academic text segmentation)
* `POST /api/ai/analyze-question` (Single question analysis)
* `POST /api/ai/analyze-questions` (Batch questions analysis)
* `POST /api/ai/assessments/{assessment}/analyze-questions` (Analyze and update assessment questions in database)

---

## 9. Testing

Run pytest:

```bash
docker compose exec ai-service pytest
```

---

## 10. Question Analysis Architecture (STEP 10)

* **Question Classification**: Pattern and keyword matching for MCQ, TRUE_FALSE, SHORT_ANSWER, DESCRIPTIVE, PROBLEM_SOLVING, CONCEPTUAL, ANALYTICAL types.
* **Topic Detection**: Uses Hugging Face `sentence-transformers/all-MiniLM-L6-v2` dense vectors and cosine similarity against course syllabus modules/topics.
* **Difficulty Analysis**: Baseline estimation evaluating linguistic complexity, cognitive depth, and question structure into EASY, MEDIUM, or HARD.
* **Cognitive Level Analysis**: Bloom's Revised Taxonomy mapping (`REMEMBER`, `UNDERSTAND`, `APPLY`, `ANALYZE`, `EVALUATE`, `CREATE`) prioritizing leading action verbs.

