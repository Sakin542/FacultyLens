"""STEP 44 regression: quality-engine results must map correctly onto recommendation-engine inputs.

Found by the AI accuracy evaluation (evaluation/): the unified pipeline passed quality-engine dicts straight
into the Recommendation*Input schemas, whose field names differ, so difficulty/marks/format signals were
silently zeroed. These tests pin the corrected behaviour without needing the embedding model.
"""

from app.schemas.quality import QualityAnalysisRequest
from app.schemas.recommendation import (
    CognitiveAnalysisInput,
    DifficultyAnalysisInput,
    MarksAnalysisInput,
    QuestionDiversityInput,
    RecommendationRequest,
    TopicAnalysisInput,
)
from app.services.assessment_quality_engine import AssessmentQualityEngine
from app.services.recommendation_engine import RecommendationEngine


def _quality(questions, declared_total, topics=None):
    engine = AssessmentQualityEngine()
    req = QualityAnalysisRequest(
        assessment={"id": 1, "title": "T", "total_marks": declared_total},
        questions=questions,
        topics=[{"name": t} for t in (topics or [])],
        learning_outcomes=[],
    )
    return engine.analyze(req).model_dump()


def _recommend(quality):
    req = RecommendationRequest(
        topic_analysis=TopicAnalysisInput.model_validate(quality["topic_analysis"]),
        difficulty_analysis=DifficultyAnalysisInput.model_validate(quality["difficulty_analysis"]),
        cognitive_analysis=CognitiveAnalysisInput.model_validate(quality["cognitive_analysis"]),
        question_diversity_analysis=QuestionDiversityInput.model_validate(quality["question_diversity_analysis"]),
        marks_analysis=MarksAnalysisInput.model_validate(quality["marks_analysis"]),
    )
    return RecommendationEngine().generate(req)


BALANCED = [
    {"id": 1, "number": 1, "text": "Define a primary key.", "marks": 2, "question_type": "CONCEPTUAL", "difficulty": "Easy", "cognitive_level": "Remember", "topics": ["Keys"]},
    {"id": 2, "number": 2, "text": "Write an SQL query to list students.", "marks": 4, "question_type": "PROBLEM_SOLVING", "difficulty": "Medium", "cognitive_level": "Apply", "topics": ["SQL"]},
    {"id": 3, "number": 3, "text": "Compare optimistic and pessimistic locking.", "marks": 4, "question_type": "ANALYTICAL", "difficulty": "Hard", "cognitive_level": "Analyze", "topics": ["Transactions"]},
    {"id": 4, "number": 4, "text": "Explain ACID with examples.", "marks": 4, "question_type": "DESCRIPTIVE", "difficulty": "Medium", "cognitive_level": "Understand", "topics": ["Transactions"]},
    {"id": 5, "number": 5, "text": "True or False: 3NF implies BCNF.", "marks": 1, "question_type": "TRUE_FALSE", "difficulty": "Easy", "cognitive_level": "Understand", "topics": ["Normalization"]},
]


def test_difficulty_percentages_are_mapped_from_quality_distribution():
    quality = _quality(BALANCED, declared_total=15, topics=["Keys", "SQL", "Transactions", "Normalization"])
    mapped = DifficultyAnalysisInput.model_validate(quality["difficulty_analysis"])
    assert mapped.easy_percentage == 40.0
    assert mapped.medium_percentage == 40.0
    assert mapped.hard_percentage == 20.0
    problems = {r.problem for r in _recommend(quality).recommendations}
    assert not any("No foundational" in p for p in problems), problems


def test_marks_mismatch_and_concentration_reach_the_recommendation_engine():
    questions = [dict(q) for q in BALANCED]
    questions[2]["marks"] = 20  # 20 of 34 marks -> 58.8% concentration
    quality = _quality(questions, declared_total=40)
    mapped = MarksAnalysisInput.model_validate(quality["marks_analysis"])
    assert mapped.marks_match is False
    assert mapped.total_marks_expected == 40.0
    assert round(mapped.total_marks_actual, 2) == 31.0
    assert mapped.max_single_question_percentage > 40.0
    categories = [r.category for r in _recommend(quality).recommendations]
    assert categories.count("marks_distribution") >= 2


def test_single_question_format_is_detected_after_mapping():
    questions = [dict(q, question_type="DESCRIPTIVE") for q in BALANCED]
    quality = _quality(questions, declared_total=15)
    mapped = QuestionDiversityInput.model_validate(quality["question_diversity_analysis"])
    assert mapped.distribution[0]["percentage"] == 100.0
    problems = {r.problem for r in _recommend(quality).recommendations}
    assert any("single format" in p.lower() for p in problems), problems


def test_topic_low_coverage_uses_engine_status_not_marks_share():
    quality = _quality(BALANCED, declared_total=15, topics=["Keys", "SQL", "Transactions", "Normalization"])
    mapped = TopicAnalysisInput.model_validate(quality["topic_analysis"])
    assert {t["status"] for t in mapped.topics} <= {"COVERED", "ADEQUATE", "LOW", "NOT_COVERED"}
    categories = [r.category for r in _recommend(quality).recommendations]
    # every topic is covered by at least one question, so no topic problem may be raised
    assert "topic_coverage" not in categories, categories


def test_legacy_recommendation_payload_still_accepted():
    legacy = DifficultyAnalysisInput(status="EVALUATED", easy_percentage=0.0, medium_percentage=50.0, hard_percentage=50.0)
    assert legacy.easy_percentage == 0.0
    marks = MarksAnalysisInput(status="MISMATCH", total_marks_expected=20, total_marks_actual=18, marks_match=False, discrepancy=-2)
    assert marks.marks_match is False


def test_unified_pipeline_raises_duplicate_and_marks_recommendations():
    """End-to-end through the unified pipeline (real MiniLM): a verbatim past question must surface as a similarity
    recommendation and a declared-total mismatch as a marks recommendation."""
    from app.schemas.assessment_analysis import UnifiedAssessmentAnalysisRequest
    from app.services.assessment_analysis_service import AssessmentAnalysisService
    from app.services.huggingface_service import get_hf_service

    service = AssessmentAnalysisService(hf_service=get_hf_service())
    req = UnifiedAssessmentAnalysisRequest(
        course_id=1,
        assessment={"id": 1, "title": "Quiz", "total_marks": 40},
        questions=[{**q, "learning_outcome_code": None} for q in BALANCED],
        learning_outcomes=[{"id": 1, "code": "LO1", "description": "Explain relational database concepts including keys and constraints."}],
        course_topics=["Keys", "SQL", "Transactions", "Normalization"],
        previous_questions=[{"id": 9, "number": 1, "text": "Compare optimistic and pessimistic locking.", "assessment_title": "Final 2025", "year": 2025}],
    )
    result = service.analyze_assessment(req)
    categories = {r["category"] for r in result["recommendations"]["recommendations"]}
    assert "semantic_similarity" in categories, categories
    assert "marks_distribution" in categories, categories
    problems = {r["problem"] for r in result["recommendations"]["recommendations"]}
    assert not any("No foundational" in p for p in problems), problems
