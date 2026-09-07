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

---

## 8. Laravel Integration

The Laravel backend communicates with this service through `App\Services\AiService`.

* **Local dev**: `AI_SERVICE_URL=http://127.0.0.1:8001`
* **Docker container**: `AI_SERVICE_URL=http://ai-service:8001`

Faculty members authenticate with Laravel Sanctum, and make requests to `POST /api/ai/analyze`, which delegates to the FastAPI microservice.

---

## 9. Testing

Run pytest:

```bash
pytest
```

---

## 10. Known Limitations (STEP 09 Scope)

* Question detection currently uses structural/regex pattern recognition.
* High-level semantic categorization (Bloom's taxonomy, cognitive level, difficulty rating) and similarity matrices will be added in subsequent steps (STEP 10+).

