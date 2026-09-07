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

