from fastapi.testclient import TestClient
from app.main import app
from app.services.text_cleaner import TextCleaner
from app.utils.text_utils import extract_questions

client = TestClient(app)


def test_text_cleaner_basic():
    raw = "1. Explain database normalization.   \r\n\r\n\r\n2. Compare SQL and NoSQL databases.\t\t"
    cleaned = TextCleaner.clean(raw)
    assert "\r" not in cleaned
    assert "\n\n\n" not in cleaned
    assert "1. Explain database normalization." in cleaned
    assert "2. Compare SQL and NoSQL databases." in cleaned


def test_question_detection_patterns():
    text_samples = """
1. Explain database normalization in DBMS.
2. Discuss B-Tree and B+ Tree indexing.
Q3. Compare relational and non-relational database models.
Question 4: Analyze ACID properties in distributed transactions.
"""
    questions = extract_questions(text_samples)
    assert len(questions) == 4
    assert questions[0]["number"] == 1
    assert "Explain database normalization" in questions[0]["text"]
    assert questions[1]["number"] == 2
    assert "Discuss B-Tree" in questions[1]["text"]
    assert questions[2]["number"] == 3
    assert "Compare relational" in questions[2]["text"]
    assert questions[3]["number"] == 4
    assert "Analyze ACID properties" in questions[3]["text"]


def test_preprocess_api_endpoint():
    payload = {
        "text": "1. What is polymorphism in OOP?\n\n2. Explain inheritance with diagrams."
    }
    response = client.post("/api/v1/preprocess", json=payload)
    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "success"
    assert "cleaned_text" in data
    assert len(data["paragraphs"]) >= 2
    assert len(data["sentences"]) >= 2


def test_preprocess_api_empty_text():
    response = client.post("/api/v1/preprocess", json={"text": "   "})
    assert response.status_code == 422
    data = response.json()
    assert data["status"] == "error"

