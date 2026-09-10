"""Pydantic schemas for STEP 32 Academic Document Chat (RAG).

Laravel performs authorization and retrieval scoping; only authorized chunks reach this
service. Retrieved text is UNTRUSTED DATA, never instructions.
"""

from typing import List, Optional

from pydantic import BaseModel, Field, field_validator


class ChatContextChunk(BaseModel):
    chunk_id: int
    document_id: int
    document_name: str = Field(..., max_length=255)
    content: str = Field(..., min_length=1, max_length=20000)
    similarity_score: float = Field(..., ge=-1, le=1)
    page_number: Optional[int] = Field(default=None, ge=1)
    section_title: Optional[str] = Field(default=None, max_length=255)
    document_type: Optional[str] = Field(default=None, max_length=50)


class ChatTurn(BaseModel):
    role: str = Field(..., pattern="^(USER|ASSISTANT|user|assistant)$")
    content: str = Field(..., max_length=20000)


class ChatScope(BaseModel):
    course_id: Optional[int] = None
    course_code: Optional[str] = Field(default=None, max_length=50)
    course_name: Optional[str] = Field(default=None, max_length=255)
    scope_type: Optional[str] = Field(default="COURSE", max_length=20)


class AcademicChatRequest(BaseModel):
    question: str = Field(..., description="Faculty question (already length-validated by Laravel)")
    retrieval_query: Optional[str] = Field(default=None, max_length=6000, description="Standalone query used for retrieval")
    context: ChatScope = Field(default_factory=ChatScope)
    chunks: List[ChatContextChunk] = Field(default_factory=list, max_length=20)
    conversation: List[ChatTurn] = Field(default_factory=list, max_length=20)

    @field_validator("question")
    @classmethod
    def validate_question(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Question cannot be empty.")
        if len(v) > 5000:
            raise ValueError("Question exceeds the maximum length of 5,000 characters.")
        return v.strip()


class ChatSourceOut(BaseModel):
    chunk_id: int
    document_id: int
    document_name: str
    page_number: Optional[int] = None
    section_title: Optional[str] = None
    similarity_score: float


class AcademicChatResponse(BaseModel):
    status: str = "success"
    answer: str = Field(..., min_length=1, max_length=20000)
    sources: List[ChatSourceOut] = Field(default_factory=list)
    grounded: bool
    generation_method: str = Field(..., description="generative | extractive | insufficient_evidence")
    model: str
    model_version: str = "1.0.0"
    embedding_model: Optional[str] = None
    prompt_version: str
    retrieved_count: int = 0
    used_count: int = 0
    disclaimer: str = (
        "Answers are generated from the academic documents available to this chat. Review the cited "
        "source material before making academic decisions. FacultyLens may miss information or "
        "misinterpret document content."
    )


class BatchEmbeddingRequest(BaseModel):
    texts: List[str] = Field(..., min_length=1, max_length=256)

    @field_validator("texts")
    @classmethod
    def validate_texts(cls, v: List[str]) -> List[str]:
        cleaned = [str(t) if t is not None else "" for t in v]
        if all(not t.strip() for t in cleaned):
            raise ValueError("At least one non-empty text is required.")
        for t in cleaned:
            if len(t) > 20000:
                raise ValueError("A text exceeds the maximum length of 20,000 characters.")
        return cleaned


class BatchEmbeddingResponse(BaseModel):
    status: str = "success"
    model: str
    embedding_dimension: int
    vectors: List[List[float]]
