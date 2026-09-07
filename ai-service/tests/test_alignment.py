import pytest
from fastapi.testclient import TestClient
from app.main import app
from app.services.similarity_service import SimilarityService
from app.services.lo_matcher import LearningOutcomeMatcher
from app.services.alignment_analyzer import AlignmentAnalyzer
from app.schemas.alignment import (
    LearningOutcomeItem,
    QuestionItem,
    ThresholdsConfig,
)

client = TestClient(app)


def test_similarity_service():
    v1 = [1.0, 0.0, 0.0]
    v2 = [1.0, 0.0, 0.0]
    v3 = [0.0, 1.0, 0.0]

    # Perfect similarity
    assert abs(SimilarityService.cosine_similarity(v1, v2) - 1.0) < 1e-5
    # Orthogonal
    assert abs(SimilarityService.cosine_similarity(v1, v3) - 0.0) < 1e-5
    # Zero vector
    assert SimilarityService.cosine_similarity([0.0, 0.0], [1.0, 1.0]) == 0.0

    # Matrix similarity
    mat_a = [[1.0, 0.0], [0.0, 1.0]]
    mat_b = [[1.0, 0.0], [0.0, 1.0], [1.0, 1.0]]
    sims = SimilarityService.compute_similarity_matrix(mat_a, mat_b)
    assert sims.shape == (2, 3)
    assert abs(sims[0, 0] - 1.0) < 1e-5
    assert abs(sims[1, 1] - 1.0) < 1e-5
    assert abs(sims[0, 1] - 0.0) < 1e-5


def test_alignment_analyzer_logic():
    analyzer = AlignmentAnalyzer()

    los = [
        LearningOutcomeItem(
            id=1,
            code="LO1",
            description="Design and implement relational databases using SQL queries and normalization.",
        ),
        LearningOutcomeItem(
            id=2,
            code="LO2",
            description="Analyze graph algorithms, shortest paths, and minimum spanning trees.",
        ),
    ]

    questions = [
        QuestionItem(
            id=101,
            number=1,
            text="Write SQL queries to join the student and enrollment tables and apply 3NF normalization.",
        ),
        QuestionItem(
            id=102,
            number=2,
            text="Calculate Dijkstra's shortest path algorithm on the given directed graph.",
        ),
    ]

    result = analyzer.analyze(
        questions=questions,
        learning_outcomes=los,
        thresholds=ThresholdsConfig(strong=0.55, weak=0.35),
    )

    assert result["status"] == "success"
    assert result["total_questions"] == 2
    assert result["total_learning_outcomes"] == 2
    assert result["overall_alignment_score"] >= 50.0
    assert len(result["question_alignment"]) == 2
    assert len(result["learning_outcome_coverage"]) == 2
    assert result["covered_learning_outcomes_count"] >= 1

    # Check Question 1 matches LO1
    q1_align = result["question_alignment"][0]
    assert q1_align["matched_learning_outcome"]["code"] == "LO1"
    assert q1_align["alignment_status"] in ["STRONG", "WEAK"]

    # Check Question 2 matches LO2
    q2_align = result["question_alignment"][1]
    assert q2_align["matched_learning_outcome"]["code"] == "LO2"


def test_api_analyze_alignment_endpoint():
    payload = {
        "course_id": 1,
        "learning_outcomes": [
            {
                "id": 1,
                "code": "LO1",
                "description": "Understand database normalization and entity-relationship models.",
            },
            {
                "id": 2,
                "code": "LO2",
                "description": "Evaluate object-oriented programming concepts like polymorphism and inheritance.",
            },
            {
                "id": 3,
                "code": "LO3",
                "description": "Implement recursive descent parsing and compiler lexical analysis.",
            }
        ],
        "questions": [
            {
                "id": 10,
                "number": "1",
                "text": "Explain second normal form (2NF) and third normal form (3NF) in relational databases.",
                "topics": ["Database Normalization", "Relational Model"]
            },
            {
                "id": 11,
                "number": "2",
                "text": "Demonstrate method overriding and polymorphism in Java with an example.",
                "topics": ["Object-Oriented Programming", "Polymorphism"]
            }
        ],
        "thresholds": {
            "strong": 0.60,
            "weak": 0.40
        }
    }

    response = client.post("/api/v1/analyze-alignment", json=payload)
    assert response.status_code == 200
    data = response.json()

    assert data["status"] == "success"
    assert data["total_questions"] == 2
    assert data["total_learning_outcomes"] == 3
    assert len(data["question_alignment"]) == 2
    assert len(data["learning_outcome_coverage"]) == 3
    assert "findings" in data
    assert isinstance(data["findings"], list)

    # LO3 should be detected as uncovered/unassessed in findings
    findings_text = " ".join(data["findings"])
    assert "LO3" in findings_text or "Unassessed" in findings_text or "Marginal" in findings_text
