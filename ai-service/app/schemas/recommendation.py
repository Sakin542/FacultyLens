"""Pydantic schemas for STEP 14 AI Recommendation Engine.

Defines input payloads (from prior analysis steps 10-13) and structured
evidence-grounded recommendation outputs for university faculty decision support.
"""

from typing import List, Optional, Dict, Any
from enum import Enum
from pydantic import BaseModel, Field


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


class LoAnalysisInput(BaseModel):
    status: Optional[str] = None
    total_los: Optional[int] = 0
    covered_los: Optional[int] = 0
    weakly_covered_los: Optional[int] = 0
    uncovered_los: Optional[int] = 0
    coverage_percentage: Optional[float] = 0.0
    learning_outcomes: Optional[List[Dict[str, Any]]] = []


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


class QuestionDiversityInput(BaseModel):
    status: Optional[str] = None
    diversity_score: Optional[float] = 0.0
    rating: Optional[str] = None
    entropy: Optional[float] = 0.0
    normalized_entropy: Optional[float] = 0.0
    distribution: Optional[List[Dict[str, Any]]] = []


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

