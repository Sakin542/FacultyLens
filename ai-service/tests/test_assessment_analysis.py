"""Unit and integration tests for STEP 15 Unified AI Assessment Analysis API.
"""

import pytest
from fastapi.testclient import TestClient
from app.main import app
from app.schemas.assessment_analysis import (
    UnifiedAssessmentAnalysisRequest,
    UnifiedQuestionInput,
    UnifiedLOInput,
    UnifiedPrevQuestionInput,
    UnifiedAssessmentMeta,
)
from app.services.huggingface_service import get_hf_service
from app.services.assessment_analysis_service import AssessmentAnalysisService

client = TestClient(app)


def test_unified_assessment_analysis_service():
    hf_service = get_hf_service()
    service = AssessmentAnalysisService(hf_service=hf_service)

    req = UnifiedAssessmentAnalysisRequest(
        course_id=1,
        course_name="Database Management Systems",
        assessment=UnifiedAssessmentMeta(
            id=10,
            title="Database Midterm 2026",
            total_marks=20.0,
            course_code="CSE-301",
            course_title="Database Management Systems",
        ),
        questions=[
            UnifiedQuestionInput(
                id=1,
                number=1,
                text="Explain the differences between B-tree and B+ tree index structures.",
                marks=10.0,
            ),
            UnifiedQuestionInput(
                id=2,
                number=2,
                text="Write an SQL query to find employees with salary greater than department average.",
                marks=10.0,
            ),
        ],
        learning_outcomes=[
            UnifiedLOInput(
                id=1,
                code="LO1",
                description="Understand and evaluate database indexing and storage architectures.",
            ),
            UnifiedLOInput(
                id=2,
                code="LO2",
                description="Write complex SQL queries involving aggregations and subqueries.",
            ),
        ],
        course_topics=["Indexing", "SQL Queries", "Normalization"],
        previous_questions=[
            UnifiedPrevQuestionInput(
                id=101,
                number=1,
                text="Describe how B+ trees store indexing pointers and records.",
                assessment_title="Midterm 2025",
            ),
        ],
    )

    result = service.analyze_assessment(req)

    assert result["status"] == "success"
    assert result["method"] == "unified_assessment_analysis_pipeline"
    assert result["assessment_id"] == 10
    assert result["course_id"] == 1

    # Verify component results
    assert "questions_analysis" in result
    assert result["questions_analysis"]["total_questions"] == 2

    assert "alignment_analysis" in result
    assert result["alignment_analysis"]["status"] == "success"
    assert len(result["alignment_analysis"]["question_alignment"]) == 2

    assert "similarity_analysis" in result
    assert result["similarity_analysis"]["status"] == "success"

    assert "quality_analysis" in result
    assert result["quality_analysis"]["status"] == "success"
    assert result["quality_analysis"]["overall_quality_score"] is not None

    assert "recommendations" in result
    assert result["recommendations"]["status"] == "success"

    assert "summary" in result
    assert result["summary"]["total_questions"] == 2
    assert result["summary"]["overall_quality_score"] is not None


def test_unified_assessment_analysis_missing_optional_inputs():
    hf_service = get_hf_service()
    service = AssessmentAnalysisService(hf_service=hf_service)

    # Missing LOs and previous questions
    req = UnifiedAssessmentAnalysisRequest(
        assessment=UnifiedAssessmentMeta(id=5, title="Quick Quiz"),
        questions=[
            UnifiedQuestionInput(
                id=1,
                number=1,
                text="What is a primary key in relational database modeling?",
                marks=5.0,
            ),
        ],
        course_topics=["Relational Model"],
    )

    result = service.analyze_assessment(req)

    assert result["status"] == "success"
    assert result["alignment_analysis"]["status"] == "UNAVAILABLE"
    assert result["similarity_analysis"]["status"] == "UNAVAILABLE"
    assert result["quality_analysis"]["status"] == "success"
    assert result["recommendations"]["status"] == "success"


def test_unified_assessment_analysis_api_endpoint():
    payload = {
        "course_id": 1,
        "assessment": {
            "id": 10,
            "title": "Database Final Exam",
            "total_marks": 50.0,
            "course_code": "CSE-301",
        },
        "questions": [
            {
                "id": 1,
                "number": 1,
                "text": "Define 3NF and BCNF with concrete relation schemas.",
                "marks": 25.0,
            },
            {
                "id": 2,
                "number": 2,
                "text": "Explain ACID properties in transaction processing.",
                "marks": 25.0,
            },
        ],
        "learning_outcomes": [
            {
                "id": 1,
                "code": "LO1",
                "description": "Demonstrate database schema normalization principles up to BCNF.",
            },
            {
                "id": 2,
                "code": "LO2",
                "description": "Analyze transaction management, ACID properties, and concurrency control.",
            },
        ],
        "course_topics": ["Normalization", "Transactions"],
    }

    response = client.post("/api/v1/analyze-assessment", json=payload)
    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "success"
    assert data["assessment_id"] == 10
    assert "quality_analysis" in data
    assert "recommendations" in data
    assert "summary" in data


def test_api_aliases():
    # 1. Question analysis alias
    q_payload = {
        "questions": [
            {"number": 1, "text": "Calculate the time complexity of merge sort."}
        ],
        "course_topics": ["Sorting Algorithms"],
    }
    resp1 = client.post("/api/v1/question-analysis", json=q_payload)
    assert resp1.status_code == 200
    assert resp1.json()["status"] == "success"
    assert resp1.json()["total_questions"] == 1

    # 2. Similarity analysis alias
    sim_payload = {
        "current_questions": [{"id": 1, "text": "What is binary search?"}],
        "previous_questions": [{"id": 2, "text": "Explain binary search algorithm."}],
    }
    resp2 = client.post("/api/v1/similarity-analysis", json=sim_payload)
    assert resp2.status_code == 200
    assert resp2.json()["status"] == "success"

    # 3. Alignment analysis alias
    align_payload = {
        "questions": [{"id": 1, "text": "Write a depth-first search graph traversal function."}],
        "learning_outcomes": [{"id": 1, "code": "LO1", "description": "Implement graph traversal algorithms."}],
    }
    resp3 = client.post("/api/v1/alignment-analysis", json=align_payload)
    assert resp3.status_code == 200
    assert resp3.json()["status"] == "success"
