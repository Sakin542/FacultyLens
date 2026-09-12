"""Pydantic schemas for STEP 14 AI Recommendation Engine.

Defines input payloads (from prior analysis steps 10-13) and structured
evidence-grounded recommendation outputs for university faculty decision support.
"""

from typing import List, Optional, Dict, Any
from enum import Enum
from pydantic import BaseModel, Field, model_validator


# STEP 44 finding: the quality engine (STEP 13) and this recommendation engine (STEP 14) name the same facts
# differently (e.g. ``question_percentage`` vs ``percentage``, ``assessment_expected_marks`` vs
# ``total_marks_expected``). Feeding a quality result straight into these inputs silently left the unmatched
# fields at their defaults, so "no easy questions" fired for every assessment while marks-mismatch,
# mark-concentration and single-format problems could never fire. The validators below accept either shape.


def _pct(items: List[Dict[str, Any]], key: str, names: List[str], field: str = "question_percentage") -> float:
    wanted = {n.lower() for n in names}
    return round(sum(float(i.get(field) or 0.0) for i in items if isinstance(i, dict) and str(i.get(key, "")).lower() in wanted), 2)


def _with_percentage(items: Any, extra: Optional[Dict[str, str]] = None) -> Any:
    if not isinstance(items, list):
        return items
    out = []
    for i in items:
        if isinstance(i, dict):
            row = dict(i)
            if row.get("percentage") is None and row.get("question_percentage") is not None:
                row["percentage"] = row["question_percentage"]
            for target, source in (extra or {}).items():
                if row.get(target) is None and row.get(source) is not None:
                    row[target] = row[source]
            out.append(row)
        else:
            out.append(i)
    return out


class RecommendationPriority(str, Enum):
    HIGH = "HIGH"
    MEDIUM = "MEDIUM"
    LOW = "LOW"


class RecommendationStatus(str, Enum):
    PENDING = "pending"
    REVIEWED = "reviewed"
    ACCEPTED = "accepted"
    DISMISSED = "dismissed"


class ProblemCategory(str, Enum):
    TOPIC_COVERAGE = "topic_coverage"
    LEARNING_OUTCOME = "learning_outcome"
    DIFFICULTY = "difficulty"
    COGNITIVE_LEVEL = "cognitive_level"
    QUESTION_DIVERSITY = "question_diversity"
    MARKS_DISTRIBUTION = "marks_distribution"
    SEMANTIC_SIMILARITY = "semantic_similarity"
    ASSESSMENT_QUALITY = "assessment_quality"


class AssessmentMeta(BaseModel):
    id: Optional[int] = None
    title: Optional[str] = None
    total_marks: Optional[float] = None
    course_code: Optional[str] = None
    course_title: Optional[str] = None


class TopicAnalysisInput(BaseModel):
    status: Optional[str] = None
    total_topics: Optional[int] = 0
    covered_topics: Optional[int] = 0
    uncovered_topics: Optional[int] = 0
    coverage_percentage: Optional[float] = 0.0
    topics: Optional[List[Dict[str, Any]]] = []

    @model_validator(mode="before")
    @classmethod
    def _from_quality_engine(cls, data: Any) -> Any:
        if not isinstance(data, dict) or "total_topics_defined" not in data:
            return data
        d = dict(data)
        total, covered = int(d.get("total_topics_defined") or 0), int(d.get("covered_topics_count") or 0)
        d.setdefault("total_topics", total)
        d.setdefault("covered_topics", covered)
        d.setdefault("uncovered_topics", max(0, total - covered))
        d.setdefault("coverage_percentage", d.get("score") or 0.0)
        d["topics"] = _with_percentage(d.get("topics"), {"name": "topic", "status": "coverage_status"})
        return d


class LoAnalysisInput(BaseModel):
    status: Optional[str] = None
    total_los: Optional[int] = 0
    covered_los: Optional[int] = 0
    weakly_covered_los: Optional[int] = 0
    uncovered_los: Optional[int] = 0
    coverage_percentage: Optional[float] = 0.0
    learning_outcomes: Optional[List[Dict[str, Any]]] = []

    @model_validator(mode="before")
    @classmethod
    def _from_quality_engine(cls, data: Any) -> Any:
        if not isinstance(data, dict) or "total_los_defined" not in data:
            return data
        d = dict(data)
        total, covered = int(d.get("total_los_defined") or 0), int(d.get("covered_los_count") or 0)
        los = d.get("learning_outcomes") or []
        d.setdefault("total_los", total)
        d.setdefault("covered_los", covered)
        d.setdefault("uncovered_los", max(0, total - covered))
        d.setdefault("weakly_covered_los", sum(1 for lo in los if isinstance(lo, dict) and str(lo.get("coverage_status", "")).upper() == "WEAK"))
        d.setdefault("coverage_percentage", d.get("score") or 0.0)
        d["learning_outcomes"] = _with_percentage(los, {"status": "coverage_status", "strong_matches_count": "strongly_aligned_questions", "weak_matches_count": "weakly_aligned_questions"})
        return d


class DifficultyAnalysisInput(BaseModel):
    status: Optional[str] = None
    balance_score: Optional[float] = 0.0
    rating: Optional[str] = None
    easy_percentage: Optional[float] = 0.0
    medium_percentage: Optional[float] = 0.0
    hard_percentage: Optional[float] = 0.0
    target_easy_percentage: Optional[float] = 30.0
    target_medium_percentage: Optional[float] = 50.0
    target_hard_percentage: Optional[float] = 20.0
    total_deviation: Optional[float] = 0.0
    distribution: Optional[List[Dict[str, Any]]] = []

    @model_validator(mode="before")
    @classmethod
    def _from_quality_engine(cls, data: Any) -> Any:
        if not isinstance(data, dict) or not isinstance(data.get("distribution"), list) or "easy_percentage" in data:
            return data
        d = dict(data)
        dist = [i for i in d["distribution"] if isinstance(i, dict)]
        if not dist or "question_percentage" not in dist[0]:
            return data
        targets = {str(i.get("level", "")).lower(): i.get("target_percentage") for i in dist}
        d.setdefault("balance_score", d.get("score") or 0.0)
        d["easy_percentage"], d["medium_percentage"], d["hard_percentage"] = _pct(dist, "level", ["Easy"]), _pct(dist, "level", ["Medium"]), _pct(dist, "level", ["Hard"])
        for level, default in (("easy", 30.0), ("medium", 50.0), ("hard", 20.0)):
            d.setdefault(f"target_{level}_percentage", targets.get(level) if targets.get(level) is not None else default)
        d["distribution"] = _with_percentage(dist)
        return d


class CognitiveAnalysisInput(BaseModel):
    status: Optional[str] = None
    diversity_score: Optional[float] = 0.0
    rating: Optional[str] = None
    entropy: Optional[float] = 0.0
    normalized_entropy: Optional[float] = 0.0
    lower_order_percentage: Optional[float] = 0.0
    medium_order_percentage: Optional[float] = 0.0
    higher_order_percentage: Optional[float] = 0.0
    distribution: Optional[List[Dict[str, Any]]] = []

    @model_validator(mode="before")
    @classmethod
    def _from_quality_engine(cls, data: Any) -> Any:
        if not isinstance(data, dict) or "shannon_entropy" not in data:
            return data
        d = dict(data)
        dist = [i for i in (d.get("distribution") or []) if isinstance(i, dict)]
        entropy, max_entropy = float(d.get("shannon_entropy") or 0.0), float(d.get("max_possible_entropy") or 0.0)
        d.setdefault("diversity_score", d.get("score") or 0.0)
        d.setdefault("entropy", entropy)
        d.setdefault("normalized_entropy", round(entropy / max_entropy * 100.0, 2) if max_entropy > 0 else 0.0)
        d.setdefault("lower_order_percentage", _pct(dist, "level", ["Remember", "Understand"]))
        d.setdefault("medium_order_percentage", _pct(dist, "level", ["Apply", "Analyze"]))
        d.setdefault("higher_order_percentage", _pct(dist, "level", ["Evaluate", "Create"]))
        d["distribution"] = _with_percentage(dist)
        return d


class QuestionDiversityInput(BaseModel):
    status: Optional[str] = None
    diversity_score: Optional[float] = 0.0
    rating: Optional[str] = None
    entropy: Optional[float] = 0.0
    normalized_entropy: Optional[float] = 0.0
    distribution: Optional[List[Dict[str, Any]]] = []

    @model_validator(mode="before")
    @classmethod
    def _from_quality_engine(cls, data: Any) -> Any:
        if not isinstance(data, dict) or "unique_types_count" not in data:
            return data
        d = dict(data)
        d.setdefault("diversity_score", d.get("score") or 0.0)
        d.setdefault("entropy", d.get("shannon_entropy") or 0.0)
        d["distribution"] = _with_percentage(d.get("distribution"), {"type": "question_type"})
        return d


class MarksAnalysisInput(BaseModel):
    status: Optional[str] = None
    integrity_score: Optional[float] = 0.0
    total_marks_expected: Optional[float] = 0.0
    total_marks_actual: Optional[float] = 0.0
    marks_match: Optional[bool] = True
    discrepancy: Optional[float] = 0.0
    max_single_question_marks: Optional[float] = 0.0
    max_single_question_percentage: Optional[float] = 0.0
    has_mark_concentration: Optional[bool] = False
    mean_marks: Optional[float] = 0.0
    std_dev_marks: Optional[float] = 0.0

    @model_validator(mode="before")
    @classmethod
    def _from_quality_engine(cls, data: Any) -> Any:
        if not isinstance(data, dict) or "total_question_marks" not in data:
            return data
        d = dict(data)
        actual = float(d.get("total_question_marks") or 0.0)
        expected = d.get("assessment_expected_marks")
        d.setdefault("integrity_score", d.get("score") or 0.0)
        d.setdefault("total_marks_actual", actual)
        d.setdefault("total_marks_expected", float(expected) if expected is not None else actual)
        d.setdefault("marks_match", bool(d.get("marks_match_assessment", True)))
        d.setdefault("discrepancy", round(actual - float(expected), 2) if expected is not None else 0.0)
        d.setdefault("max_single_question_marks", d.get("max_marks") or 0.0)
        d.setdefault("max_single_question_percentage", d.get("highest_single_question_share") or 0.0)
        d.setdefault("has_mark_concentration", bool(d.get("high_concentration_detected", False)))
        d.setdefault("mean_marks", d.get("average_marks") or 0.0)
        return d


class SimilarityAnalysisInput(BaseModel):
    status: Optional[str] = None
    overall_similarity_score: Optional[float] = 0.0
    potential_duplicates_count: Optional[int] = 0
    highly_similar_count: Optional[int] = 0
    somewhat_similar_count: Optional[int] = 0
    total_questions: Optional[int] = 0
    matches: Optional[List[Dict[str, Any]]] = []
    question_similarities: Optional[List[Dict[str, Any]]] = []


class QualityAnalysisInput(BaseModel):
    overall_quality_score: Optional[float] = None
    rating: Optional[str] = None
    components: Optional[Dict[str, Any]] = None


class RecommendationRequest(BaseModel):
    assessment: Optional[AssessmentMeta] = None
    topic_analysis: Optional[TopicAnalysisInput] = None
    learning_outcome_analysis: Optional[LoAnalysisInput] = None
    difficulty_analysis: Optional[DifficultyAnalysisInput] = None
    cognitive_analysis: Optional[CognitiveAnalysisInput] = None
    question_diversity_analysis: Optional[QuestionDiversityInput] = None
    marks_analysis: Optional[MarksAnalysisInput] = None
    similarity_analysis: Optional[SimilarityAnalysisInput] = None
    quality_analysis: Optional[QualityAnalysisInput] = None
    custom_rules: Optional[Dict[str, Any]] = None


class DetectedProblem(BaseModel):
    code: str = Field(..., description="Unique problem code e.g. LO_NOT_COVERED, TOPIC_NOT_COVERED")
    category: ProblemCategory
    title: str
    explanation: str
    evidence: Dict[str, Any]
    source_metric: str
    severity: RecommendationPriority


class RecommendationItem(BaseModel):
    id: Optional[str] = None
    category: str
    problem: str
    evidence: Dict[str, Any]
    explanation: str
    recommendation: str
    priority: str
    source_metric: str
    status: str = "pending"


class RecommendationResponse(BaseModel):
    status: str = "success"
    method: str = "evidence_based_recommendation_engine"
    total_recommendations: int
    high_priority_count: int
    medium_priority_count: int
    low_priority_count: int
    recommendations: List[RecommendationItem]

