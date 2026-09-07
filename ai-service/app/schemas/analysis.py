from typing import List, Optional
from pydantic import BaseModel, Field, field_validator


class HealthResponse(BaseModel):
    status: str = Field(..., json_schema_extra={"example": "ok"})
    service: str = Field(..., json_schema_extra={"example": "FacultyLens AI Service"})
    model: str = Field(..., json_schema_extra={"example": "sentence-transformers/all-MiniLM-L6-v2"})
    model_loaded: bool = Field(..., json_schema_extra={"example": True})


class PreprocessRequest(BaseModel):
    text: str = Field(..., description="Raw academic text to preprocess")

    @field_validator("text")
    @classmethod
    def validate_text(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Text cannot be empty or whitespace only.")
        if len(v) > 50000:
            raise ValueError("Text exceeds maximum allowed length of 50,000 characters.")
        return v.strip()


class PreprocessResponse(BaseModel):
    status: str = "success"
    cleaned_text: str
    paragraphs: List[str]
    sentences: List[str]


class QuestionItem(BaseModel):
    number: int
    text: str


class AnalysisDetails(BaseModel):
    character_count: int
    word_count: int
    sentence_count: int
    paragraph_count: int
    questions_detected: int
    questions: List[QuestionItem]
    keywords: List[str] = Field(default_factory=list)
    embeddings_generated: bool
    embedding_dimension: int
    model: str


class AnalyzeRequest(BaseModel):
    document_type: str = Field(
        default="question_paper",
        description="Type of academic document (e.g., question_paper, syllabus, learning_outcomes)",
    )
    text: str = Field(..., description="Academic text to analyze")

    @field_validator("document_type")
    @classmethod
    def validate_document_type(cls, v: str) -> str:
        if not v or not v.strip():
            return "general"
        return v.strip().lower()

    @field_validator("text")
    @classmethod
    def validate_text(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Text cannot be empty or whitespace only.")
        if len(v) > 50000:
            raise ValueError("Text exceeds maximum allowed length of 50,000 characters.")
        return v.strip()


class AnalyzeResponse(BaseModel):
    status: str = "success"
    document_type: str
    analysis: AnalysisDetails


class EmbeddingRequest(BaseModel):
    text: str = Field(..., description="Text to encode into embedding vector")
    return_vector: bool = Field(default=False, description="Whether to include full numerical vector")

    @field_validator("text")
    @classmethod
    def validate_text(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Text cannot be empty or whitespace only.")
        if len(v) > 50000:
            raise ValueError("Text exceeds maximum allowed length of 50,000 characters.")
        return v.strip()


class EmbeddingResponse(BaseModel):
    status: str = "success"
    embedding_dimension: int
    model: str
    vector: Optional[List[float]] = None


# STEP 10: Question Analysis Schemas

class TopicItem(BaseModel):
    name: str
    confidence: Optional[float] = None


class ClassificationResult(BaseModel):
    question_type: str
    confidence: Optional[float] = None


class DifficultyResult(BaseModel):
    level: str
    method: str = "baseline"


class CognitiveResult(BaseModel):
    level: str
    method: str = "baseline"


class AnalyzeSingleQuestionRequest(BaseModel):
    question: str = Field(..., description="Academic question text to analyze")
    course_topics: Optional[List[str]] = Field(
        default=None, description="Optional list of course topics/learning outcomes to match against"
    )

    @field_validator("question")
    @classmethod
    def validate_question(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Question text cannot be empty.")
        if len(v) > 10000:
            raise ValueError("Question text exceeds maximum length of 10,000 characters.")
        return v.strip()


class AnalyzeSingleQuestionResponse(BaseModel):
    status: str = "success"
    question: str
    classification: ClassificationResult
    topics: List[TopicItem]
    difficulty: DifficultyResult
    cognitive_level: CognitiveResult


class QuestionBatchItem(BaseModel):
    number: Optional[int] = None
    text: str = Field(..., description="Text of the question")

    @field_validator("text")
    @classmethod
    def validate_text(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Question text cannot be empty.")
        return v.strip()


class AnalyzeBatchQuestionsRequest(BaseModel):
    questions: List[QuestionBatchItem] = Field(..., description="List of questions to analyze")
    course_topics: Optional[List[str]] = Field(
        default=None, description="Optional list of course syllabus topics"
    )

    @field_validator("questions")
    @classmethod
    def validate_questions(cls, v: List[QuestionBatchItem]) -> List[QuestionBatchItem]:
        if not v:
            raise ValueError("Questions list cannot be empty.")
        if len(v) > 100:
            raise ValueError("Maximum of 100 questions can be analyzed in a single batch.")
        return v


class AnalyzedQuestionDetail(BaseModel):
    number: int
    question: str
    classification: ClassificationResult
    topics: List[TopicItem]
    difficulty: DifficultyResult
    cognitive_level: CognitiveResult


class AnalysisSummary(BaseModel):
    question_types: dict
    difficulty_distribution: dict
    cognitive_distribution: dict
    topics_detected: List[str]


class AnalyzeBatchQuestionsResponse(BaseModel):
    status: str = "success"
    total_questions: int
    questions: List[AnalyzedQuestionDetail]
    summary: Optional[AnalysisSummary] = None


