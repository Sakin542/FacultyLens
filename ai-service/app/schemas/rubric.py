"""Pydantic schemas for STEP 25 AI Rubric Generator.

The rubric produced here is an AI-generated DRAFT. It is an assistive artifact
that faculty must review, edit and approve before use. Nothing in this module
claims correctness, fairness or grading authority.
"""

from enum import Enum
from typing import List, Optional

from pydantic import BaseModel, Field, field_validator, model_validator


class RubricQuestionType(str, Enum):
    """Mirrors the question types used across FacultyLens (Laravel stores lowercase)."""

    MCQ = "MCQ"
    SHORT_ANSWER = "SHORT_ANSWER"
    DESCRIPTIVE = "DESCRIPTIVE"
    PROBLEM_SOLVING = "PROBLEM_SOLVING"
    TRUE_FALSE = "TRUE_FALSE"
    CONCEPTUAL = "CONCEPTUAL"
    ANALYTICAL = "ANALYTICAL"
    OTHER = "OTHER"


class RubricGenerationMethod(str, Enum):
    AI_ASSISTED = "ai_assisted"
    TEMPLATE_BASED = "template_based"


class LearningOutcomeContext(BaseModel):
    code: Optional[str] = Field(default=None, max_length=50)
    description: Optional[str] = Field(default=None, max_length=2000)


class CourseContext(BaseModel):
    course_code: Optional[str] = Field(default=None, max_length=50)
    course_name: Optional[str] = Field(default=None, max_length=255)


class GenerateRubricRequest(BaseModel):
    question_id: Optional[int] = Field(default=None, description="Laravel question identifier (informational)")
    question_text: str = Field(..., description="Full text of the assessment question")
    question_type: RubricQuestionType = Field(default=RubricQuestionType.DESCRIPTIVE)
    total_marks: float = Field(..., gt=0, le=1000, description="Total marks allocated to the question")
    difficulty_level: Optional[str] = Field(default=None, max_length=20)
    cognitive_level: Optional[str] = Field(default=None, max_length=20)
    expected_answer: Optional[str] = Field(default=None, max_length=10000)
    learning_outcome: Optional[LearningOutcomeContext] = None
    course_context: Optional[CourseContext] = None

    @field_validator("question_text")
    @classmethod
    def validate_question_text(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Question text cannot be empty or whitespace only.")
        if len(v) > 10000:
            raise ValueError("Question text exceeds maximum allowed length of 10,000 characters.")
        return v.strip()

    @field_validator("question_type", mode="before")
    @classmethod
    def normalize_question_type(cls, v):
        if v is None:
            return RubricQuestionType.DESCRIPTIVE
        if isinstance(v, RubricQuestionType):
            return v
        normalized = str(v).strip().upper().replace("-", "_").replace(" ", "_")
        aliases = {
            "MULTIPLE_CHOICE": "MCQ",
            "SHORT": "SHORT_ANSWER",
            "ESSAY": "DESCRIPTIVE",
            "LONG_ANSWER": "DESCRIPTIVE",
            "NUMERICAL": "PROBLEM_SOLVING",
            "CODING": "PROBLEM_SOLVING",
            "TF": "TRUE_FALSE",
        }
        normalized = aliases.get(normalized, normalized)
        if normalized not in RubricQuestionType.__members__:
            raise ValueError(
                f"Unsupported question type '{v}'. Supported: "
                + ", ".join(RubricQuestionType.__members__.keys())
            )
        return RubricQuestionType[normalized]

    @field_validator("difficulty_level", "cognitive_level", mode="before")
    @classmethod
    def normalize_level(cls, v):
        if v is None:
            return None
        text = str(v).strip()
        return text.upper() if text else None


class RubricCriterionOut(BaseModel):
    criterion: str = Field(..., min_length=1, max_length=255)
    description: str = Field(..., min_length=1, max_length=2000)
    max_marks: float = Field(..., ge=0)
    scoring_guidance: str = Field(default="", max_length=2000)
    expected_indicators: List[str] = Field(default_factory=list)
    sort_order: int = Field(..., ge=1)


class RubricOut(BaseModel):
    title: str = Field(..., min_length=1, max_length=255)
    question_text: str
    total_marks: float = Field(..., gt=0)
    criteria: List[RubricCriterionOut] = Field(..., min_length=1)
    general_guidance: str = Field(default="", max_length=2000)

    @model_validator(mode="after")
    def marks_must_sum_to_total(self):
        total = round(sum(c.max_marks for c in self.criteria), 2)
        if abs(total - round(self.total_marks, 2)) > 0.005:
            raise ValueError(
                f"Criterion marks ({total}) do not sum to the question total ({self.total_marks})."
            )
        return self


class RubricGenerationMetadata(BaseModel):
    model: str = Field(..., description="Model or engine that produced the draft")
    version: str = Field(default="1.0.0")
    embedding_model: Optional[str] = None
    generative_model_used: bool = False
    criteria_count: int = 0
    validation_passed: bool = True
    disclaimer: str = (
        "AI-generated draft rubric. Faculty review and approval are required before use."
    )


class GenerateRubricResponse(BaseModel):
    status: str = "success"
    generation_method: RubricGenerationMethod
    draft_status: str = "DRAFT"
    rubric: RubricOut
    metadata: RubricGenerationMetadata
