import pytest
from fastapi.testclient import TestClient

from app.main import app
from app.schemas.quality import (
    QualityAnalysisRequest,
    AssessmentQuestionInput,
    TopicInput,
    LearningOutcomeInput,
    AssessmentMetadataInput,
    QualityWeightsConfig,
    DifficultyTargetsConfig,
)
from app.services.assessment_quality_engine import AssessmentQualityEngine


@pytest.fixture
def sample_balanced_questions():
    return [
        AssessmentQuestionInput(
            number=1,
            text="Define relational data model and explain candidate keys.",
            marks=10.0,
            question_type="Descriptive",
            difficulty="Easy",
            cognitive_level="Remember",
            topics=["Relational Data Model", "Keys"],
            learning_outcome_code="CLO-1",
        ),
        AssessmentQuestionInput(
            number=2,
            text="Explain the differences between 2NF and 3NF with real-world table schemas.",
            marks=15.0,
            question_type="Conceptual",
            difficulty="Medium",
            cognitive_level="Understand",
            topics=["Normalization", "Database Design"],
            learning_outcome_code="CLO-2",
        ),
        AssessmentQuestionInput(
            number=3,
            text="Write SQL queries using joins, grouping, and aggregations to analyze customer sales.",
            marks=25.0,
            question_type="Problem Solving",
            difficulty="Medium",
            cognitive_level="Apply",
            topics=["SQL Querying", "Relational Algebra"],
            learning_outcome_code="CLO-3",
        ),
        AssessmentQuestionInput(
            number=4,
            text="Analyze concurrency conflict serializability in the given transaction schedule.",
            marks=25.0,
            question_type="Analytical",
            difficulty="Hard",
            cognitive_level="Analyze",
            topics=["Transaction Management", "Concurrency Control"],
            learning_outcome_code="CLO-4",
        ),
        AssessmentQuestionInput(
            number=5,
            text="Evaluate two indexing strategies (B+ tree vs Hash Index) for high-frequency range queries.",
            marks=25.0,
            question_type="Descriptive",
            difficulty="Hard",
            cognitive_level="Evaluate",
            topics=["Indexing", "Query Optimization"],
            learning_outcome_code="CLO-5",
        ),
    ]


@pytest.fixture
def sample_topics():
    return [
        TopicInput(name="Relational Data Model"),
        TopicInput(name="Normalization"),
        TopicInput(name="SQL Querying"),
        TopicInput(name="Transaction Management"),
        TopicInput(name="Indexing"),
    ]


@pytest.fixture
def sample_los():
    return [
        LearningOutcomeInput(code="CLO-1", description="Explain relational data models"),
        LearningOutcomeInput(code="CLO-2", description="Apply normalization techniques"),
        LearningOutcomeInput(code="CLO-3", description="Formulate complex SQL queries"),
        LearningOutcomeInput(code="CLO-4", description="Analyze transaction concurrency"),
        LearningOutcomeInput(code="CLO-5", description="Evaluate database indexing strategies"),
    ]


def test_quality_engine_balanced_assessment(sample_balanced_questions, sample_topics, sample_los):
    engine = AssessmentQualityEngine()
    request = QualityAnalysisRequest(
        assessment=AssessmentMetadataInput(id=1, title="Final Exam", total_marks=100.0),
        questions=sample_balanced_questions,
        topics=sample_topics,
        learning_outcomes=sample_los,
    )

    response = engine.analyze(request)

    assert response.status == "success"
    assert response.overall_quality_score is not None
    assert response.overall_quality_score >= 75.0
    assert response.rating in ("EXCELLENT", "GOOD", "FAIR")
    assert response.topic_analysis.status == "AVAILABLE"
    assert response.topic_analysis.score == 100.0
    assert response.learning_outcome_analysis.status == "AVAILABLE"
    assert response.learning_outcome_analysis.score == 100.0
    assert response.marks_analysis.marks_match_assessment is True
    assert len(response.findings) > 0


def test_quality_engine_missing_topics_and_los(sample_balanced_questions):
    engine = AssessmentQualityEngine()
    # No topics and no LOs provided
    request = QualityAnalysisRequest(
        assessment=AssessmentMetadataInput(id=1, total_marks=100.0),
        questions=sample_balanced_questions,
        topics=[],
        learning_outcomes=[],
    )

    response = engine.analyze(request)

    assert response.status == "success"
    assert response.topic_analysis.status == "UNAVAILABLE"
    assert response.topic_analysis.score is None
    assert response.learning_outcome_analysis.status == "UNAVAILABLE"
    assert response.learning_outcome_analysis.score is None

    # Should re-normalize remaining 4 available components
    assert "topic" in response.excluded_components
    assert "learning_outcome" in response.excluded_components
    assert response.overall_quality_score is not None
    assert len(response.weights_applied) == 4
    # Check sum of normalized weights equals 100%
    assert abs(sum(response.weights_applied.values()) - 100.0) < 0.2


def test_quality_engine_topic_coverage_missing_topic(sample_balanced_questions):
    topics_with_missing = [
        TopicInput(name="Relational Data Model"),
        TopicInput(name="Distributed Systems"),  # Uncovered in questions
    ]

    engine = AssessmentQualityEngine()
    request = QualityAnalysisRequest(
        assessment=AssessmentMetadataInput(total_marks=100.0),
        questions=sample_balanced_questions,
        topics=topics_with_missing,
    )

    response = engine.analyze(request)

    assert response.topic_analysis.status == "AVAILABLE"
    assert response.topic_analysis.total_topics_defined == 2
    assert response.topic_analysis.covered_topics_count == 1
    assert response.topic_analysis.score == 50.0
    assert any("Distributed Systems" in f for f in response.findings)


def test_quality_engine_lo_coverage_missing_lo(sample_balanced_questions):
    los_with_missing = [
        LearningOutcomeInput(code="CLO-1", description="Explain relational data models"),
        LearningOutcomeInput(code="CLO-9", description="Design NoSQL databases"),  # Uncovered
    ]

    engine = AssessmentQualityEngine()
    request = QualityAnalysisRequest(
        assessment=AssessmentMetadataInput(total_marks=100.0),
        questions=sample_balanced_questions,
        learning_outcomes=los_with_missing,
    )

    response = engine.analyze(request)

    assert response.learning_outcome_analysis.status == "AVAILABLE"
    assert response.learning_outcome_analysis.total_los_defined == 2
    assert response.learning_outcome_analysis.covered_los_count == 1
    assert response.learning_outcome_analysis.score == 50.0
    assert any("CLO-9" in f for f in response.findings)


def test_quality_engine_difficulty_skew():
    # All Easy questions
    all_easy_questions = [
        AssessmentQuestionInput(
            number=i,
            text=f"Easy question text {i}",
            marks=10.0,
            difficulty="Easy",
            cognitive_level="Remember",
            question_type="MCQ",
        )
        for i in range(1, 11)
    ]

    engine = AssessmentQualityEngine()
    request = QualityAnalysisRequest(
        assessment=AssessmentMetadataInput(total_marks=100.0),
        questions=all_easy_questions,
    )

    response = engine.analyze(request)

    assert response.difficulty_analysis.status == "AVAILABLE"
    # Target is Easy 30%, Actual is Easy 100% -> deviation is 70 + 50 + 20 = 140
    assert response.difficulty_analysis.total_deviation >= 100.0
    assert response.difficulty_analysis.score <= 50.0
    assert any("Easy questions" in f for f in response.findings)


def test_quality_engine_cognitive_concentration():
    # All Understand level
    all_understand_questions = [
        AssessmentQuestionInput(
            number=i,
            text=f"Explain concept {i}",
            marks=20.0,
            difficulty="Medium",
            cognitive_level="Understand",
            question_type="Descriptive",
        )
        for i in range(1, 6)
    ]

    engine = AssessmentQualityEngine()
    request = QualityAnalysisRequest(
        assessment=AssessmentMetadataInput(total_marks=100.0),
        questions=all_understand_questions,
    )

    response = engine.analyze(request)

    assert response.cognitive_analysis.status == "AVAILABLE"
    # Single cognitive tier -> Shannon entropy = 0, score = 0
    assert response.cognitive_analysis.shannon_entropy == 0.0
    assert response.cognitive_analysis.score == 0.0
    assert response.cognitive_analysis.dominant_level == "Understand"
    assert response.cognitive_analysis.dominant_percentage == 100.0
    assert any("High concentration detected" in f for f in response.findings)


def test_quality_engine_single_question_format():
    # 100% Multiple Choice Questions
    all_mcq_questions = [
        AssessmentQuestionInput(
            number=i,
            text=f"Select the best answer {i}",
            marks=10.0,
            difficulty="Medium",
            cognitive_level="Apply",
            question_type="MCQ",
        )
        for i in range(1, 11)
    ]

    engine = AssessmentQualityEngine()
    request = QualityAnalysisRequest(
        assessment=AssessmentMetadataInput(total_marks=100.0),
        questions=all_mcq_questions,
    )

    response = engine.analyze(request)

    assert response.question_diversity_analysis.status == "AVAILABLE"
    assert response.question_diversity_analysis.unique_types_count == 1
    assert response.question_diversity_analysis.score == 0.0
    assert any("Single format detected" in f for f in response.findings)


def test_quality_engine_marks_mismatch_and_extreme_concentration():
    # 3 questions totaling 80 marks, but assessment expects 100 marks
    # Q1 has 60 marks (75% concentration)
    questions = [
        AssessmentQuestionInput(number=1, text="Massive project question", marks=60.0, question_type="Problem Solving"),
        AssessmentQuestionInput(number=2, text="Short question", marks=10.0, question_type="Short Answer"),
        AssessmentQuestionInput(number=3, text="Another short question", marks=10.0, question_type="Short Answer"),
    ]

    engine = AssessmentQualityEngine()
    request = QualityAnalysisRequest(
        assessment=AssessmentMetadataInput(total_marks=100.0),
        questions=questions,
    )

    response = engine.analyze(request)

    assert response.marks_analysis.marks_match_assessment is False
    assert response.marks_analysis.total_question_marks == 80.0
    assert response.marks_analysis.high_concentration_detected is True
    assert response.marks_analysis.highest_single_question_share == 75.0
    assert response.marks_analysis.score <= 65.0
    assert any("Marks Mismatch" in f for f in response.findings)
    assert any("High Mark Concentration" in f for f in response.findings)


def test_api_analyze_assessment_quality_endpoint(sample_balanced_questions, sample_topics, sample_los):
    client = TestClient(app)

    payload = {
        "assessment": {
            "id": 10,
            "title": "Midterm Examination",
            "total_marks": 100.0,
        },
        "questions": [q.model_dump() for q in sample_balanced_questions],
        "topics": [t.model_dump() for t in sample_topics],
        "learning_outcomes": [lo.model_dump() for lo in sample_los],
    }

    response = client.post("/api/v1/analyze-assessment-quality", json=payload)

    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "success"
    assert data["method"] == "assessment_quality_engine"
    assert data["overall_quality_score"] is not None
    assert "components" in data
    assert "topic_analysis" in data
    assert "learning_outcome_analysis" in data
    assert "difficulty_analysis" in data
    assert "cognitive_analysis" in data
    assert "question_diversity_analysis" in data
    assert "marks_analysis" in data
    assert len(data["findings"]) > 0

