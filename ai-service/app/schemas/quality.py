from typing import List, Optional, Dict, Any, Union
from pydantic import BaseModel, Field, field_validator


class AssessmentMetadataInput(BaseModel):
    id: Optional[Union[int, str]] = None
    title: Optional[str] = None
    total_marks: Optional[float] = None


class AssessmentQuestionInput(BaseModel):
    id: Optional[Union[int, str]] = None
    number: Optional[int] = None
    text: str = Field(..., description="Text of the question")
    marks: Optional[float] = Field(default=1.0, description="Marks allocated for this question")
    question_type: Optional[str] = Field(default=None, description="Format (MCQ, Descriptive, Problem Solving, etc.)")
    difficulty: Optional[str] = Field(default=None, description="Difficulty level (Easy, Medium, Hard)")
    cognitive_level: Optional[str] = Field(default=None, description="Bloom's level (Remember, Understand, Apply, Analyze, Evaluate, Create)")
    topics: Optional[List[str]] = Field(default_factory=list, description="Detected or tagged syllabus topics")
    learning_outcome_code: Optional[str] = Field(default=None, description="Mapped LO code (e.g. CLO-1)")
    similarity_score: Optional[float] = Field(default=None, description="Highest similarity score to past questions")
    is_duplicate: Optional[bool] = Field(default=False, description="Flagged as potential duplicate")

    @field_validator("text")
    @classmethod
    def validate_text(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Question text cannot be empty.")
        return v.strip()


class TopicInput(BaseModel):
    name: str = Field(..., description="Name of syllabus topic")
    weight: Optional[float] = Field(default=None, description="Optional relative weight (0-100 or fractional)")


class LearningOutcomeInput(BaseModel):
    code: str = Field(..., description="LO Identifier/Code, e.g. CLO-1")
    description: Optional[str] = Field(default="", description="Description of LO")
    weight: Optional[float] = Field(default=None, description="Optional relative weight")


class QualityWeightsConfig(BaseModel):
    topic: float = Field(default=20.0, description="Weight percentage for topic coverage (default: 20%)")
    lo: float = Field(default=20.0, description="Weight percentage for LO coverage (default: 20%)")
    difficulty: float = Field(default=15.0, description="Weight percentage for difficulty balance (default: 15%)")
    cognitive: float = Field(default=15.0, description="Weight percentage for cognitive diversity (default: 15%)")
    question_diversity: float = Field(default=15.0, description="Weight percentage for question format diversity (default: 15%)")
    marks: float = Field(default=15.0, description="Weight percentage for marks distribution (default: 15%)")


class DifficultyTargetsConfig(BaseModel):
    easy: float = Field(default=30.0, description="Target percentage for Easy questions (default: 30%)")
    medium: float = Field(default=50.0, description="Target percentage for Medium questions (default: 50%)")
    hard: float = Field(default=20.0, description="Target percentage for Hard questions (default: 20%)")


class QualityAnalysisRequest(BaseModel):
    assessment: Optional[AssessmentMetadataInput] = None
    questions: List[AssessmentQuestionInput] = Field(..., description="List of examination questions")
    topics: Optional[List[TopicInput]] = Field(default_factory=list, description="Course syllabus topics")
    learning_outcomes: Optional[List[LearningOutcomeInput]] = Field(default_factory=list, description="Course Learning Outcomes")
    weights: Optional[QualityWeightsConfig] = None
    difficulty_targets: Optional[DifficultyTargetsConfig] = None

    @field_validator("questions")
    @classmethod
    def validate_questions(cls, v: List[AssessmentQuestionInput]) -> List[AssessmentQuestionInput]:
        if not v:
            raise ValueError("Questions list cannot be empty. At least one question is required for quality analysis.")
        return v


# Component Result Schemas

class TopicCoverageItem(BaseModel):
    topic: str
    question_count: int
    marks: float
    coverage_percentage: float
    coverage_status: str  # COVERED, ADEQUATE, LOW, NOT_COVERED


class TopicAnalysisResult(BaseModel):
    status: str  # AVAILABLE, UNAVAILABLE
    score: Optional[float] = None
    methodology: str
    total_topics_defined: int
    covered_topics_count: int
    topics: List[TopicCoverageItem]


class LoCoverageItem(BaseModel):
    code: str
    description: Optional[str] = ""
    question_count: int
    marks: float
    strongly_aligned_questions: int
    weakly_aligned_questions: int
    coverage_percentage: float
    coverage_status: str  # COVERED, ADEQUATE, WEAK, NOT_COVERED


class LoAnalysisResult(BaseModel):
    status: str  # AVAILABLE, UNAVAILABLE
    score: Optional[float] = None
    methodology: str
    total_los_defined: int
    covered_los_count: int
    learning_outcomes: List[LoCoverageItem]


class DifficultyDistributionItem(BaseModel):
    level: str  # Easy, Medium, Hard
    question_count: int
    question_percentage: float
    marks: float
    marks_percentage: float
    target_percentage: float
    deviation: float


class DifficultyAnalysisResult(BaseModel):
    status: str  # AVAILABLE, UNAVAILABLE
    score: Optional[float] = None
    methodology: str
    total_deviation: float
    distribution: List[DifficultyDistributionItem]


class CognitiveLevelItem(BaseModel):
    level: str  # Remember, Understand, Apply, Analyze, Evaluate, Create
    question_count: int
    question_percentage: float
    marks: float
    marks_percentage: float


class CognitiveAnalysisResult(BaseModel):
    status: str  # AVAILABLE, UNAVAILABLE
    score: Optional[float] = None
    methodology: str
    shannon_entropy: float
    max_possible_entropy: float
    dominant_level: Optional[str] = None
    dominant_percentage: float
    distribution: List[CognitiveLevelItem]


class QuestionTypeItem(BaseModel):
    question_type: str
    question_count: int
    question_percentage: float
    marks: float
    marks_percentage: float


class QuestionDiversityResult(BaseModel):
    status: str  # AVAILABLE, UNAVAILABLE
    score: Optional[float] = None
    methodology: str
    unique_types_count: int
    shannon_entropy: float
    dominant_type: Optional[str] = None
    dominant_percentage: float
    distribution: List[QuestionTypeItem]


class MarksAnalysisResult(BaseModel):
    status: str  # AVAILABLE, UNAVAILABLE
    score: Optional[float] = None
    methodology: str
    total_question_marks: float
    assessment_expected_marks: Optional[float] = None
    marks_match_assessment: bool
    average_marks: float
    min_marks: float
    max_marks: float
    median_marks: float
    high_concentration_detected: bool
    highest_single_question_share: float
    highest_single_question_number: Optional[int] = None
    marks_by_topic: Dict[str, float] = Field(default_factory=dict)
    marks_by_lo: Dict[str, float] = Field(default_factory=dict)
    marks_by_difficulty: Dict[str, float] = Field(default_factory=dict)
    marks_by_cognitive: Dict[str, float] = Field(default_factory=dict)


class ComponentScores(BaseModel):
    topic_coverage: Optional[float] = None
    learning_outcome_coverage: Optional[float] = None
    difficulty_balance: Optional[float] = None
    cognitive_diversity: Optional[float] = None
    question_diversity: Optional[float] = None
    marks_distribution: Optional[float] = None


class QualityAnalysisResponse(BaseModel):
    status: str = "success"
    method: str = "assessment_quality_engine"
    overall_quality_score: Optional[float] = None
    rating: str  # EXCELLENT, GOOD, FAIR, NEEDS_REVIEW, REQUIRES_ATTENTION, UNAVAILABLE
    weights_applied: Dict[str, float]
    excluded_components: List[str] = Field(default_factory=list)
    components: ComponentScores
    topic_analysis: TopicAnalysisResult
    learning_outcome_analysis: LoAnalysisResult
    difficulty_analysis: DifficultyAnalysisResult
    cognitive_analysis: CognitiveAnalysisResult
    question_diversity_analysis: QuestionDiversityResult
    marks_analysis: MarksAnalysisResult
    findings: List[str]

