from fastapi.testclient import TestClient
from app.main import app

client = TestClient(app)


def test_analyze_endpoint_real_academic_text():
    academic_text = """
1. Explain database normalization.
2. Describe the difference between SQL and NoSQL databases.
3. Compare relational and non-relational database systems.
"""
    payload = {
        "document_type": "question_paper",
        "text": academic_text,
    }
    response = client.post("/api/v1/analyze", json=payload)
    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "success"
    assert data["document_type"] == "question_paper"

    analysis = data["analysis"]
    assert analysis["character_count"] > 0
    assert analysis["word_count"] > 0
    assert analysis["sentence_count"] >= 3
    assert analysis["questions_detected"] == 3
    assert len(analysis["questions"]) == 3
    assert analysis["questions"][0]["number"] == 1
    assert "Explain database normalization" in analysis["questions"][0]["text"]
    assert analysis["questions"][1]["number"] == 2
    assert analysis["questions"][2]["number"] == 3
    assert analysis["embedding_dimension"] == 384
    assert "all-MiniLM-L6-v2" in analysis["model"]


def test_analyze_endpoint_validation_errors():
    # Empty text
    res1 = client.post("/api/v1/analyze", json={"document_type": "question_paper", "text": ""})
    assert res1.status_code == 422

    # Whitespace only
    res2 = client.post("/api/v1/analyze", json={"document_type": "question_paper", "text": "   \n\t  "})
    assert res2.status_code == 422


def test_embedding_endpoint():
    payload = {
        "text": "Explain database normalization and relational integrity constraints.",
        "return_vector": False,
    }
    response = client.post("/api/v1/embedding", json=payload)
    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "success"
    assert data["embedding_dimension"] == 384
    assert "all-MiniLM-L6-v2" in data["model"]
    assert data["vector"] is None


def test_embedding_endpoint_with_vector():
    payload = {
        "text": "Short query for vector inspection.",
        "return_vector": True,
    }
    response = client.post("/api/v1/embedding", json=payload)
    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "success"
    assert data["embedding_dimension"] == 384
    assert isinstance(data["vector"], list)
    assert len(data["vector"]) == 384

