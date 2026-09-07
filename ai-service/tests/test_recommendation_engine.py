"""Unit and integration tests for STEP 14 AI Recommendation Engine.
"""

import pytest
from fastapi.testclient import TestClient
from app.main import app
from app.schemas.recommendation import (
    RecommendationRequest,
    TopicAnalysisInput,
    LoAnalysisInput,
    DifficultyAnalysisInput,
    CognitiveAnalysisInput,
    QuestionDiversityInput,
    MarksAnalysisInput,
    SimilarityAnalysisInput,
    QualityAnalysisInput,
    AssessmentMeta,
)
from app.services.recommendation_engine import RecommendationEngine


client = TestClient(app)


def test_uncovered_topic_detection():
    engine = RecommendationEngine()
    req = RecommendationRequest(
        assessment=AssessmentMeta(id=1, title="Midterm Exam"),
        topic_analysis=TopicAnalysisInput(
            status="ADEQUATE",
            total_topics=3,
            covered_topics=2,
            uncovered_topics=1,
            coverage_percentage=66.7,
            topics=[
                {"name": "ER Modeling", "status": "ADEQUATE", "question_count": 2, "coverage_percentage": 50.0},
                {"name": "SQL Queries", "status": "ADEQUATE", "question_count": 2, "coverage_percentage": 50.0},
                {"name": "Transaction Recovery", "status": "NOT_COVERED", "question_count": 0, "coverage_percentage": 0.0},
            ],
        ),
    )

    res = engine.generate(req)
    assert res.total_recommendations >= 1
    topic_rec = next((r for r in res.recommendations if r.category == "topic_coverage"), None)
    assert topic_rec is not None
    assert "Transaction Recovery" in topic_rec.problem
    assert topic_rec.priority in ("HIGH", "MEDIUM")
    assert "Consider adding or revising" in topic_rec.recommendation
    assert topic_rec.evidence["status"] == "NOT_COVERED"


def test_uncovered_and_weak_lo_detection():
    engine = RecommendationEngine()
    req = RecommendationRequest(
        learning_outcome_analysis=LoAnalysisInput(
            status="WEAK",
            total_los=3,
            covered_los=1,
            weakly_covered_los=1,
            uncovered_los=1,
            coverage_percentage=33.3,
            learning_outcomes=[
                {"code": "LO1", "description": "Design relational schema", "status": "COVERED", "question_count": 2, "strong_matches_count": 2, "max_similarity": 0.88},
                {"code": "LO2", "description": "Implement indexing", "status": "WEAKLY_COVERED", "question_count": 1, "strong_matches_count": 0, "weak_matches_count": 1, "max_similarity": 0.58},
                {"code": "LO3", "description": "Analyze query plans", "status": "NOT_COVERED", "question_count": 0, "strong_matches_count": 0, "weak_matches_count": 0, "max_similarity": 0.32},
            ],
        ),
    )

    res = engine.generate(req)
    lo_recs = [r for r in res.recommendations if r.category == "learning_outcome"]
    assert len(lo_recs) == 2

    lo3_rec = next((r for r in lo_recs if "LO3" in r.problem), None)
    assert lo3_rec is not None
    assert lo3_rec.priority == "HIGH"
    assert lo3_rec.evidence["aligned_questions_count"] == 0

    lo2_rec = next((r for r in lo_recs if "LO2" in r.problem), None)
    assert lo2_rec is not None
    assert lo2_rec.priority == "MEDIUM"


def test_difficulty_imbalance_and_skew():
    engine = RecommendationEngine()
    req = RecommendationRequest(
        difficulty_analysis=DifficultyAnalysisInput(
            status="IMBALANCED",
            balance_score=50.0,
            easy_percentage=0.0,
            medium_percentage=80.0,
            hard_percentage=20.0,
            target_easy_percentage=30.0,
            target_medium_percentage=50.0,
            target_hard_percentage=20.0,
            total_deviation=60.0,
        ),
    )

    res = engine.generate(req)
    diff_rec = next((r for r in res.recommendations if r.category == "difficulty"), None)
    assert diff_rec is not None
    assert "Easy" in diff_rec.problem or "foundational" in diff_rec.problem.lower()
    assert "Consider introducing foundational" in diff_rec.recommendation


def test_cognitive_concentration():
    engine = RecommendationEngine()
    req = RecommendationRequest(
        cognitive_analysis=CognitiveAnalysisInput(
            status="CONCENTRATED",
            diversity_score=35.0,
            lower_order_percentage=75.0,
            distribution=[
                {"level": "Remember", "percentage": 40.0},
                {"level": "Understand", "percentage": 35.0},
                {"level": "Apply", "percentage": 25.0},
                {"level": "Analyze", "percentage": 0.0},
                {"level": "Evaluate", "percentage": 0.0},
                {"level": "Create", "percentage": 0.0},
            ],
        ),
    )

    res = engine.generate(req)
    cog_recs = [r for r in res.recommendations if r.category == "cognitive_level"]
    assert len(cog_recs) >= 1
    assert any("Lower-Order" in r.problem or "Remember" in r.problem for r in cog_recs)


def test_single_question_format():
    engine = RecommendationEngine()
    req = RecommendationRequest(
        question_diversity_analysis=QuestionDiversityInput(
            status="SINGLE_FORMAT",
            diversity_score=0.0,
            distribution=[
                {"type": "Multiple Choice", "percentage": 100.0},
            ],
        ),
    )

    res = engine.generate(req)
    q_rec = next((r for r in res.recommendations if r.category == "question_diversity"), None)
    assert q_rec is not None
    assert "single format" in q_rec.problem.lower()
    assert "Multiple Choice" in q_rec.problem


def test_marks_mismatch_and_concentration():
    engine = RecommendationEngine()
    req = RecommendationRequest(
        marks_analysis=MarksAnalysisInput(
            status="MISMATCH_AND_CONCENTRATED",
            total_marks_expected=100.0,
            total_marks_actual=85.0,
            marks_match=False,
            discrepancy=-15.0,
            max_single_question_marks=45.0,
            max_single_question_percentage=45.0,
            has_mark_concentration=True,
        ),
    )

    res = engine.generate(req)
    marks_recs = [r for r in res.recommendations if r.category == "marks_distribution"]
    assert len(marks_recs) == 2

    mismatch = next((r for r in marks_recs if "mismatch" in r.problem.lower() or "not match" in r.problem.lower()), None)
    assert mismatch is not None
    assert mismatch.priority == "HIGH"
    assert mismatch.evidence["discrepancy"] == -15.0

    concentration = next((r for r in marks_recs if "concentration" in r.problem.lower()), None)
    assert concentration is not None
    assert concentration.priority == "HIGH"
    assert concentration.evidence["percentage_of_total"] == 45.0


def test_semantic_similarity_duplicate():
    engine = RecommendationEngine()
    req = RecommendationRequest(
        similarity_analysis=SimilarityAnalysisInput(
            status="DUPLICATES_FOUND",
            potential_duplicates_count=1,
            highly_similar_count=0,
            matches=[
                {
                    "current_question_number": 4,
                    "current_question_text": "Calculate the closure of attribute set {A, B} under functional dependencies F.",
                    "max_similarity_score": 0.93,
                    "max_similarity_status": "POTENTIAL_DUPLICATE",
                    "matches": [
                        {
                            "source_assessment": "Fall 2024 Final Exam",
                            "similarity_score": 0.93,
                        }
                    ],
                }
            ],
        ),
    )

    res = engine.generate(req)
    sim_rec = next((r for r in res.recommendations if r.category == "semantic_similarity"), None)
    assert sim_rec is not None
    assert sim_rec.priority == "HIGH"
    assert "Question #4" in sim_rec.problem
    assert "Fall 2024 Final Exam" in sim_rec.explanation or "Fall 2024 Final Exam" in sim_rec.recommendation


def test_no_problems_produces_empty_recommendations():
    engine = RecommendationEngine()
    req = RecommendationRequest(
        topic_analysis=TopicAnalysisInput(
            status="BALANCED",
            total_topics=2,
            covered_topics=2,
            uncovered_topics=0,
            coverage_percentage=100.0,
            topics=[
                {"name": "Topic 1", "status": "ADEQUATE", "question_count": 2, "coverage_percentage": 50.0},
                {"name": "Topic 2", "status": "ADEQUATE", "question_count": 2, "coverage_percentage": 50.0},
            ],
        ),
        learning_outcome_analysis=LoAnalysisInput(
            status="BALANCED",
            total_los=2,
            covered_los=2,
            uncovered_los=0,
            coverage_percentage=100.0,
            learning_outcomes=[
                {"code": "LO1", "status": "COVERED", "question_count": 2, "strong_matches_count": 2, "max_similarity": 0.85},
                {"code": "LO2", "status": "COVERED", "question_count": 2, "strong_matches_count": 2, "max_similarity": 0.82},
            ],
        ),
        difficulty_analysis=DifficultyAnalysisInput(
            status="BALANCED",
            balance_score=95.0,
            easy_percentage=30.0,
            medium_percentage=50.0,
            hard_percentage=20.0,
            total_deviation=0.0,
        ),
        cognitive_analysis=CognitiveAnalysisInput(
            status="BALANCED",
            diversity_score=85.0,
            lower_order_percentage=40.0,
            distribution=[
                {"level": "Remember", "percentage": 20.0},
                {"level": "Apply", "percentage": 40.0},
                {"level": "Analyze", "percentage": 40.0},
            ],
        ),
        marks_analysis=MarksAnalysisInput(
            status="BALANCED",
            total_marks_expected=100.0,
            total_marks_actual=100.0,
            marks_match=True,
            discrepancy=0.0,
            max_single_question_percentage=20.0,
            has_mark_concentration=False,
        ),
        similarity_analysis=SimilarityAnalysisInput(
            status="NO_DUPLICATES",
            potential_duplicates_count=0,
            highly_similar_count=0,
            matches=[],
        ),
        quality_analysis=QualityAnalysisInput(
            overall_quality_score=92.0,
            rating="EXCELLENT",
        ),
    )

    res = engine.generate(req)
    assert res.total_recommendations == 0
    assert len(res.recommendations) == 0


def test_priority_sorting():
    engine = RecommendationEngine()
    req = RecommendationRequest(
        learning_outcome_analysis=LoAnalysisInput(
            status="WEAK",
            learning_outcomes=[
                {"code": "LO_UNCOVERED", "status": "NOT_COVERED", "question_count": 0, "strong_matches_count": 0, "max_similarity": 0.2},
                {"code": "LO_WEAK", "status": "WEAKLY_COVERED", "question_count": 1, "strong_matches_count": 0, "max_similarity": 0.55},
            ],
        ),
        question_diversity_analysis=QuestionDiversityInput(
            status="LOW_DIVERSITY",
            distribution=[{"type": "Descriptive", "percentage": 85.0}],
        ),
    )

    res = engine.generate(req)
    assert res.total_recommendations >= 3
    priorities = [r.priority for r in res.recommendations]
    # Check that HIGH appears before MEDIUM, and MEDIUM appears before LOW
    high_idx = [i for i, p in enumerate(priorities) if p == "HIGH"]
    med_idx = [i for i, p in enumerate(priorities) if p == "MEDIUM"]
    low_idx = [i for i, p in enumerate(priorities) if p == "LOW"]

    if high_idx and med_idx:
        assert max(high_idx) < min(med_idx)
    if med_idx and low_idx:
        assert max(med_idx) < min(low_idx)


def test_api_generate_recommendations_endpoint():
    payload = {
        "assessment": {"id": 10, "title": "Final Exam 2026", "total_marks": 100},
        "learning_outcome_analysis": {
            "status": "UNBALANCED",
            "learning_outcomes": [
                {"code": "LO3", "description": "Distributed databases", "status": "NOT_COVERED", "question_count": 0, "strong_matches_count": 0, "max_similarity": 0.25}
            ]
        },
        "marks_analysis": {
            "status": "MISMATCH",
            "total_marks_expected": 100.0,
            "total_marks_actual": 90.0,
            "marks_match": False,
            "discrepancy": -10.0,
            "max_single_question_percentage": 25.0
        }
    }

    response = client.post("/api/v1/generate-recommendations", json=payload)
    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "success"
    assert data["total_recommendations"] >= 2
    assert data["high_priority_count"] >= 2
    assert any(r["category"] == "learning_outcome" for r in data["recommendations"])
    assert any(r["category"] == "marks_distribution" for r in data["recommendations"])

