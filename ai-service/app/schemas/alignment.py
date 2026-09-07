from typing import List, Optional, Dict, Any, Union
from pydantic import BaseModel, Field


class LearningOutcomeItem(BaseModel):
    id: Optional[Union[int, str]] = Field(None, description="Optional LO identifier")
    code: Optional[str] = Field(None, description="Learning Outcome code (e.g., LO1, CLO-2)")
    description: str = Field(..., description="Full text description of the learning outcome")


class QuestionItem(BaseModel):
    id: Optional[Union[int, str]] = Field(None, description="Optional question identifier")
    number: Optional[Union[int, str]] = Field(None, description="Question number or label (e.g., 1, '1(a)')")
    text: str = Field(..., description="The complete text of the question")
    topics: Optional[List[str]] = Field(default_factory=list, description="Extracted or associated course topics")


class ThresholdsConfig(BaseModel):
    strong: float = Field(0.70, description="Threshold for strong semantic alignment (>= strong)")
    weak: float = Field(0.50, description="Threshold for weak semantic alignment (>= weak and < strong)")


class AnalyzeAlignmentRequest(BaseModel):
    course_id: Optional[int] = Field(None, description="Associated course ID")
    learning_outcomes: List[LearningOutcomeItem] = Field(..., min_length=1, description="List of course learning outcomes")
    questions: List[QuestionItem] = Field(..., min_length=1, description="List of examination questions")
    thresholds: Optional[ThresholdsConfig] = Field(default_factory=ThresholdsConfig, description="Configurable alignment thresholds")


class MatchedLearningOutcome(BaseModel):
    id: Optional[Union[int, str]] = None
    code: Optional[str] = None
    description: str
    similarity: float
    alignment_level: str = Field(..., description="'STRONG', 'WEAK', or 'NOT_ALIGNED'")


class AlternativeMatch(BaseModel):
    id: Optional[Union[int, str]] = None
    code: Optional[str] = None
    description: str
    similarity: float
    alignment_level: str


class QuestionAlignmentResult(BaseModel):
    question_id: Optional[Union[int, str]] = None
    question_number: Optional[Union[int, str]] = None
    question_text: str
    matched_learning_outcome: Optional[MatchedLearningOutcome] = None
    alternative_matches: List[AlternativeMatch] = Field(default_factory=list)
    alignment_status: str = Field(..., description="'STRONG', 'WEAK', or 'NOT_ALIGNED'")
    similarity_score: float = Field(0.0, description="Highest similarity score with any LO")
    reasoning: Optional[str] = None


class LoCoverageResult(BaseModel):
    learning_outcome_id: Optional[Union[int, str]] = None
    code: Optional[str] = None
    description: str
    coverage_status: str = Field(..., description="'COVERED', 'WEAKLY_COVERED', or 'NOT_COVERED'")
    matching_questions_count: int = 0
    matching_question_numbers: List[Union[int, str]] = Field(default_factory=list)
    max_similarity: float = 0.0


class AnalyzeAlignmentResponse(BaseModel):
    status: str = "success"
    method: str = "sentence-transformers/all-MiniLM-L6-v2 + cosine_similarity"
    overall_alignment_score: float = Field(..., description="Overall alignment score (0-100)")
    aligned_questions_count: int = Field(..., description="Count of questions with strong or weak alignment")
    total_questions: int
    total_learning_outcomes: int
    covered_learning_outcomes_count: int
    question_alignment: List[QuestionAlignmentResult]
    learning_outcome_coverage: List[LoCoverageResult]
    findings: List[str]
    thresholds: Dict[str, float]
