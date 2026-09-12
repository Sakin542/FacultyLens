"""STEP 45: explainability schemas (deterministic, evidence-based; no hidden reasoning)."""

from typing import Any, Dict, List, Optional

from pydantic import BaseModel, Field


class ExplainQuestionRequest(BaseModel):
    question_text: str = Field(..., min_length=1, max_length=20000)
    question_type: Optional[str] = Field(default=None, description="Stored AI question type (for consistency check)")
    difficulty_level: Optional[str] = Field(default=None, description="Stored AI difficulty (EASY/MEDIUM/HARD)")
    cognitive_level: Optional[str] = Field(default=None, description="Stored AI Bloom level")
    topics: Optional[List[str]] = Field(default=None, description="Stored AI topics")
    course_topics: Optional[List[str]] = Field(default=None, description="Course topics available at analysis time")


class CueEvidence(BaseModel):
    type: str = "question_text"
    text: str
    label: str
    position: int


class ConfidenceOut(BaseModel):
    available: bool
    value: Optional[float] = None


class LabelExplanation(BaseModel):
    label: str
    detected_label: str
    consistent: bool
    method: str
    confidence: ConfidenceOut
    evidence: List[CueEvidence]
    summary: str
    factors: Any = None
    factor_names: Optional[List[str]] = None
    leading_verb: Optional[bool] = None


class TopicExplanation(BaseModel):
    labels: List[str]
    method: str
    evidence: List[CueEvidence]
    context: str
    summary: str


class ExplainQuestionResponse(BaseModel):
    status: str = "success"
    question_type: LabelExplanation
    difficulty: LabelExplanation
    cognitive_level: LabelExplanation
    topics: TopicExplanation
    model: Dict[str, Optional[str]]
    limitations: Dict[str, List[str]]
    explanation_version: str
    untrusted_content_detected: bool = False


class ValidateExplanationRequest(BaseModel):
    explanation: str = Field(..., max_length=20000)
    facts: Dict[str, Any] = Field(default_factory=dict)
    kind: Optional[str] = Field(default=None, description="similarity | alignment | quality | generic (for fallback text)")


class ValidateExplanationResponse(BaseModel):
    status: str = "success"
    valid: bool
    violations: List[str]
    explanation: Optional[str] = Field(default=None, description="Validated text, or the deterministic fallback when invalid")
    fallback_used: bool = False
