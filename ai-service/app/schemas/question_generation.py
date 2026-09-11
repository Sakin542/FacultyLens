"""STEP 33: Constrained Question Generator schemas."""

from typing import Dict, List, Literal, Optional

from pydantic import BaseModel, Field, field_validator

QuestionType = Literal["MCQ", "SHORT_ANSWER", "DESCRIPTIVE", "PROBLEM_SOLVING", "TRUE_FALSE", "CONCEPTUAL", "ANALYTICAL"]
DifficultyLevel = Literal["EASY", "MEDIUM", "HARD"]
CognitiveLevel = Literal["REMEMBER", "UNDERSTAND", "APPLY", "ANALYZE", "EVALUATE", "CREATE"]

QUESTION_TYPES = ["MCQ", "SHORT_ANSWER", "DESCRIPTIVE", "PROBLEM_SOLVING", "TRUE_FALSE", "CONCEPTUAL", "ANALYTICAL"]
DIFFICULTY_LEVELS = ["EASY", "MEDIUM", "HARD"]
COGNITIVE_LEVELS = ["REMEMBER", "UNDERSTAND", "APPLY", "ANALYZE", "EVALUATE", "CREATE"]


class CourseContext(BaseModel):
    course_code: Optional[str] = Field(None, max_length=50)
    course_name: Optional[str] = Field(None, max_length=255)
    description: Optional[str] = Field(None, max_length=4000)


class OutcomeContext(BaseModel):
    code: str = Field(..., max_length=50)
    description: str = Field(..., max_length=4000)
    cognitive_level: Optional[str] = Field(None, max_length=30)


class ProgramOutcomeContext(BaseModel):
    code: str = Field(..., max_length=50)
    title: str = Field(..., max_length=255)
    description: Optional[str] = Field(None, max_length=4000)


class DocumentContextChunk(BaseModel):
    chunk_id: Optional[int] = None
    document_id: Optional[int] = None
    document_name: str = Field("Document", max_length=255)
    content: str = Field(..., min_length=1, max_length=20000)
    page_number: Optional[int] = None
    section_title: Optional[str] = Field(None, max_length=255)
    similarity_score: Optional[float] = None


class ExistingQuestion(BaseModel):
    id: Optional[int] = None
    text: str = Field(..., min_length=1, max_length=10000)
    source: str = Field("assessment", max_length=40)  # assessment | previous | question_bank
    label: Optional[str] = Field(None, max_length=255)


class BlueprintSlot(BaseModel):
    difficulty_level: Optional[DifficultyLevel] = None
    cognitive_level: Optional[CognitiveLevel] = None
    question_type: Optional[QuestionType] = None
    marks: Optional[float] = Field(None, gt=0, le=1000)
    count: int = Field(1, ge=1, le=20)


class GenerateQuestionsRequest(BaseModel):
    course_context: CourseContext = Field(default_factory=CourseContext)
    learning_outcome: Optional[OutcomeContext] = None
    program_outcome: Optional[ProgramOutcomeContext] = None
    topic: Optional[str] = Field(None, max_length=255)
    question_type: QuestionType = "DESCRIPTIVE"
    difficulty_level: Optional[DifficultyLevel] = None
    cognitive_level: Optional[CognitiveLevel] = None
    marks: float = Field(10, gt=0, le=1000)
    number_of_questions: int = Field(1, ge=1, le=20)
    language: str = Field("English", max_length=40)
    include_expected_answer: bool = True
    include_explanation: bool = False
    document_context: List[DocumentContextChunk] = Field(default_factory=list, max_length=20)
    existing_question_context: List[ExistingQuestion] = Field(default_factory=list, max_length=500)
    blueprint: Optional[List[BlueprintSlot]] = Field(None, max_length=20)
    feedback: List[str] = Field(default_factory=list, max_length=10)
    similarity_thresholds: Optional[Dict[str, float]] = None  # duplicate/high/moderate
    alignment_thresholds: Optional[Dict[str, float]] = None  # strong/weak

    @field_validator("topic")
    @classmethod
    def _strip_topic(cls, v: Optional[str]) -> Optional[str]:
        v = (v or "").strip()
        return v or None

    @field_validator("feedback")
    @classmethod
    def _clean_feedback(cls, v: List[str]) -> List[str]:
        return [f.strip()[:500] for f in v if f and f.strip()]


class ConstraintChecks(BaseModel):
    topic: Optional[bool] = None
    question_type: bool = True
    difficulty: Optional[bool] = None
    cognitive_level: Optional[bool] = None
    co_alignment: Optional[bool] = None
    similarity: bool = True
    marks: bool = True


class SimilarityMatch(BaseModel):
    existing_id: Optional[int] = None
    source: str
    label: Optional[str] = None
    text: str
    similarity_score: float
    status: str  # POTENTIAL_DUPLICATE | HIGHLY_SIMILAR | SOMEWHAT_SIMILAR | NOT_SIMILAR


class QuestionValidation(BaseModel):
    detected_question_type: Optional[str] = None
    detected_difficulty: Optional[str] = None
    detected_cognitive_level: Optional[str] = None
    detected_topics: List[str] = Field(default_factory=list)
    co_alignment_score: Optional[float] = None
    co_alignment_status: Optional[str] = None  # STRONG | WEAK | NOT_ALIGNED
    max_similarity_score: Optional[float] = None
    similarity_status: Optional[str] = None
    similar_questions: List[SimilarityMatch] = Field(default_factory=list)
    constraints: ConstraintChecks = Field(default_factory=ConstraintChecks)
    warnings: List[str] = Field(default_factory=list)
    overall_status: str = "PENDING"  # PASSED | PASSED_WITH_WARNINGS | FAILED


class GeneratedQuestionOut(BaseModel):
    question_text: str
    question_type: QuestionType
    marks: float
    difficulty_level: Optional[DifficultyLevel] = None
    cognitive_level: Optional[CognitiveLevel] = None
    topic: Optional[str] = None
    options: Optional[List[str]] = None
    correct_option: Optional[str] = None
    expected_answer: Optional[str] = None
    explanation: Optional[str] = None
    source_chunk_ids: List[int] = Field(default_factory=list)
    validation: QuestionValidation = Field(default_factory=QuestionValidation)


class BlueprintSummary(BaseModel):
    requested: Dict[str, Dict[str, int]] = Field(default_factory=dict)
    generated: Dict[str, Dict[str, int]] = Field(default_factory=dict)
    matches: bool = True


class GenerateQuestionsResponse(BaseModel):
    status: str = "success"
    questions: List[GeneratedQuestionOut]
    generation_method: str  # generative | template
    model: str
    model_version: str
    embedding_model: str
    prompt_version: str
    requested_count: int
    generated_count: int
    blueprint_summary: Optional[BlueprintSummary] = None
    warnings: List[str] = Field(default_factory=list)
    disclaimer: str
