import pytest
from fastapi.testclient import TestClient
from app.main import app
from app.services.semantic_similarity_analyzer import SemanticSimilarityAnalyzer
from app.schemas.similarity import (
    CurrentQuestionItem,
    PreviousQuestionItem,
    SimilarityThresholdsConfig,
)

client = TestClient(app)


def test_semantic_similarity_exact_duplicate():
    analyzer = SemanticSimilarityAnalyzer()

    current_q = [
        CurrentQuestionItem(
            id=1,
            question_number=1,
            text="Define normalization in relational databases.",
        )
    ]
    prev_q = [
        PreviousQuestionItem(
            id=101,
            text="Define normalization in relational databases.",
            source_year=2025,
            source_assessment="Midterm Exam",
        )
    ]

    result = analyzer.analyze(current_questions=current_q, previous_questions=prev_q)
    assert result["status"] == "success"
    assert result["total_current_questions"] == 1
    assert result["total_previous_questions"] == 1
    assert result["potential_duplicates_count"] == 1

    match = result["results"][0]["matches"][0]
    assert match["similarity_score"] >= 0.95
    assert match["similarity_status"] == "POTENTIAL_DUPLICATE"
    assert result["results"][0]["max_similarity_status"] == "POTENTIAL_DUPLICATE"


def test_semantic_similarity_paraphrased_duplicate():
    analyzer = SemanticSimilarityAnalyzer()

    current_q = [
        CurrentQuestionItem(
            id=2,
            question_number=2,
            text="Explain the advantages and benefits of database normalization.",
        )
    ]
    prev_q = [
        PreviousQuestionItem(
            id=102,
            text="Describe the importance and merits of normalization in relational database design.",
            source_year=2024,
            source_assessment="Final Exam",
        )
    ]

    result = analyzer.analyze(current_questions=current_q, previous_questions=prev_q)
    match = result["results"][0]["matches"][0]
    # Paraphrase should yield strong similarity
    assert match["similarity_score"] >= 0.70
    assert match["similarity_status"] in ["POTENTIAL_DUPLICATE", "HIGHLY_SIMILAR"]


def test_semantic_similarity_different_questions():
    analyzer = SemanticSimilarityAnalyzer()

    current_q = [
        CurrentQuestionItem(
            id=3,
            question_number=3,
            text="Explain the concept of ACID properties in transaction processing.",
        )
    ]
    prev_q = [
        PreviousQuestionItem(
            id=103,
            text="Write a Python script to perform binary search on an array.",
            source_year=2023,
            source_assessment="Quiz 1",
        )
    ]

    result = analyzer.analyze(current_questions=current_q, previous_questions=prev_q)
    match = result["results"][0]["matches"][0]
    assert match["similarity_score"] < 0.50
    assert match["similarity_status"] == "NOT_SIMILAR"
    assert result["results"][0]["max_similarity_status"] == "NOT_SIMILAR"


def test_semantic_similarity_same_topic_different_task():
    analyzer = SemanticSimilarityAnalyzer()

    current_q = [
        CurrentQuestionItem(
            id=4,
            question_number=4,
            text="Define normalization and list normal forms.",
            question_type="CONCEPTUAL",
            cognitive_level="REMEMBER",
        )
    ]
    prev_q = [
        PreviousQuestionItem(
            id=104,
            text="Apply 3NF and BCNF normalization rules to decompose the given relation schema R(A,B,C,D,E).",
            question_type="PROBLEM_SOLVING",
            cognitive_level="APPLY",
            source_year=2025,
            source_assessment="Final Exam",
        )
    ]

    result = analyzer.analyze(current_questions=current_q, previous_questions=prev_q)
    # The topic is shared (normalization), but task is different.
    # The system should compute similarity and provide cognitive context.
    assert result["results"][0]["current_cognitive_level"] == "REMEMBER"
    assert result["results"][0]["matches"][0]["cognitive_level"] == "APPLY"
    assert result["results"][0]["reasoning"] is not None


def test_semantic_similarity_empty_previous_questions():
    analyzer = SemanticSimilarityAnalyzer()

    current_q = [
        CurrentQuestionItem(
            id=5,
            question_number=1,
            text="What is a primary key in relational tables?",
        )
    ]

    result = analyzer.analyze(current_questions=current_q, previous_questions=[])
    assert result["status"] == "success"
    assert result["total_previous_questions"] == 0
    assert result["potential_duplicates_count"] == 0
    assert result["results"][0]["max_similarity_status"] == "NOT_SIMILAR"
    assert len(result["results"][0]["matches"]) == 0


def test_semantic_similarity_top_k_limiting():
    analyzer = SemanticSimilarityAnalyzer()

    current_q = [
        CurrentQuestionItem(
            id=6,
            question_number=1,
            text="Explain B-tree indexing in database systems.",
        )
    ]
    prev_q = [
        PreviousQuestionItem(id=i, text=f"Question {i} about database indexing and performance.")
        for i in range(10)
    ]

    result = analyzer.analyze(current_questions=current_q, previous_questions=prev_q, top_k=3)
    assert len(result["results"][0]["matches"]) == 3


def test_api_analyze_similarity_endpoint():
    payload = {
        "course_id": 1,
        "current_questions": [
            {
                "id": 1,
                "question_number": 1,
                "text": "Explain two-phase locking protocol (2PL) in concurrency control.",
                "question_type": "DESCRIPTIVE",
                "cognitive_level": "UNDERSTAND"
            },
            {
                "id": 2,
                "question_number": 2,
                "text": "What is an ER diagram?",
                "question_type": "CONCEPTUAL",
                "cognitive_level": "REMEMBER"
            }
        ],
        "previous_questions": [
            {
                "id": 101,
                "text": "Explain two-phase locking protocol (2PL) in concurrency control.",
                "source_year": 2025,
                "source_assessment": "Final Exam"
            },
            {
                "id": 102,
                "text": "Describe entity-relationship modeling and entity types.",
                "source_year": 2024,
                "source_assessment": "Midterm Exam"
            }
        ],
        "thresholds": {
            "duplicate": 0.85,
            "high": 0.70,
            "moderate": 0.50,
            "top_k": 3
        }
    }

    response = client.post("/api/v1/analyze-similarity", json=payload)
    assert response.status_code == 200
    data = response.json()

    assert data["status"] == "success"
    assert data["total_current_questions"] == 2
    assert data["total_previous_questions"] == 2
    assert data["potential_duplicates_count"] >= 1
    assert len(data["results"]) == 2
    assert len(data["findings"]) > 0

    # Q1 should match with Q101 as POTENTIAL_DUPLICATE
    q1_result = data["results"][0]
    assert q1_result["max_similarity_status"] == "POTENTIAL_DUPLICATE"
    assert q1_result["matches"][0]["previous_question_id"] == 101

