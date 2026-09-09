"""Pydantic schemas for STEP 27 AI Grading Assistance.

Everything produced here is a *suggestion*. Faculty review the suggested marks,
criterion evaluations and feedback in Laravel/React and make the final grading
decision. Nothing in this module finalizes a grade or claims correctness.
"""

from typing import List, Optional

from pydantic import BaseModel, Field, field_validator, model_validator

from app.schemas.rubric import CourseContext, LearningOutcomeContext, RubricQuestionType

MARK_TOLERANCE = 0.005


class GradingAnswerIn(BaseModel):
    id: Optional[int] = Field(default=None, description="Laravel student_answer identifier (informational)")
    text: str = Field(..., description="Student answer text (typed or extracted from a document)")
    answer_type: Optional[str] = Field(default="TEXT", max_length=20)

    @field_validator("text")
    @classmethod
    def validate_text(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Student answer text cannot be empty. Image-only answers are not supported for AI grading.")
        if len(v) > 60000:
            raise ValueError("Student answer text exceeds the maximum allowed length of 60,000 characters.")
        return v.strip()


class GradingQuestionIn(BaseModel):
    id: Optional[int] = None
    text: str = Field(..., description="Full question text")
    total_marks: float = Field(..., gt=0, le=1000)
    question_type: RubricQuestionType = Field(default=RubricQuestionType.DESCRIPTIVE)
    difficulty_level: Optional[str] = Field(default=None, max_length=20)
    cognitive_level: Optional[str] = Field(default=None, max_length=20)
    expected_answer: Optional[str] = Field(default=None, max_length=10000)

    @field_validator("text")
    @classmethod
    def validate_question_text(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Question text cannot be empty.")
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
            return RubricQuestionType.OTHER
        return RubricQuestionType[normalized]

    @field_validator("difficulty_level", "cognitive_level", mode="before")
    @classmethod
    def normalize_level(cls, v):
        if v is None:
            return None
        text = str(v).strip()
        return text.upper() if text else None


class GradingCriterionIn(BaseModel):
    id: int = Field(..., description="Laravel rubric_criterion identifier")
    criterion: str = Field(..., min_length=1, max_length=255)
    description: str = Field(default="", max_length=2000)
    max_marks: float = Field(..., ge=0)
    scoring_guidance: Optional[str] = Field(default=None, max_length=2000)
    expected_indicators: List[str] = Field(default_factory=list)
    sort_order: Optional[int] = Field(default=None, ge=1)

    @field_validator("expected_indicators", mode="before")
    @classmethod
    def clean_indicators(cls, v):
        if not v:
            return []
        cleaned = []
        for item in v:
            text = str(item).strip()
            if text and text not in cleaned:
                cleaned.append(text[:255])
        return cleaned[:12]


class GradingRubricIn(BaseModel):
    id: Optional[int] = None
    version: Optional[int] = None
    total_marks: float = Field(..., gt=0)
    general_guidance: Optional[str] = Field(default=None, max_length=2000)
    criteria: List[GradingCriterionIn] = Field(..., min_length=1, max_length=12)

    @model_validator(mode="after")
    def criteria_must_be_consistent(self):
        ids = [c.id for c in self.criteria]
        if len(ids) != len(set(ids)):
            raise ValueError("Rubric criteria identifiers must be unique.")
        total = round(sum(c.max_marks for c in self.criteria), 2)
        if abs(total - round(self.total_marks, 2)) > MARK_TOLERANCE:
            raise ValueError(
                f"Rubric criterion marks ({total}) do not sum to the rubric total ({self.total_marks})."
            )
        return self


class GradeAnswerRequest(BaseModel):
    student_answer: GradingAnswerIn
    question: GradingQuestionIn
    rubric: GradingRubricIn
    learning_outcome: Optional[LearningOutcomeContext] = None
    course_context: Optional[CourseContext] = None

    @model_validator(mode="after")
    def rubric_must_match_question(self):
        if abs(round(self.rubric.total_marks, 2) - round(self.question.total_marks, 2)) > MARK_TOLERANCE:
            raise ValueError(
                f"Rubric total ({self.rubric.total_marks}) does not match the question marks ({self.question.total_marks})."
            )
        return self


class CriterionGradingResult(BaseModel):
    rubric_criterion_id: int
    criterion: str = Field(..., min_length=1, max_length=255)
    suggested_marks: float = Field(..., ge=0)
    maximum_marks: float = Field(..., ge=0)
    evaluation: str = Field(..., min_length=1, max_length=2000)
    evidence: List[str] = Field(default_factory=list)
    missing_elements: List[str] = Field(default_factory=list)
    coverage_level: str = Field(..., description="STRONG, PARTIAL, LIMITED or NOT_ADDRESSED")

    @model_validator(mode="after")
    def marks_within_bounds(self):
        if self.suggested_marks > self.maximum_marks + MARK_TOLERANCE:
            raise ValueError(
                f"Criterion '{self.criterion}' suggested marks ({self.suggested_marks}) exceed the maximum ({self.maximum_marks})."
            )
        return self


class GradingMetadata(BaseModel):
    model: str = Field(..., description="Engine or model that produced the suggestion")
    version: str = Field(default="1.0.0")
    embedding_model: Optional[str] = None
    generative_model_used: bool = False
    generation_method: str = Field(default="embedding_rubric_alignment")
    criteria_count: int = 0
    validation_passed: bool = True
    disclaimer: str = (
        "AI-generated grading assistance. Faculty review is required before finalizing marks."
    )


class GradeAnswerResponse(BaseModel):
    status: str = "success"
    suggested_marks: float = Field(..., ge=0)
    maximum_marks: float = Field(..., gt=0)
    criterion_results: List[CriterionGradingResult] = Field(..., min_length=1)
    overall_feedback: str = Field(..., min_length=1, max_length=5000)
    strengths: List[str] = Field(default_factory=list)
    missing_elements: List[str] = Field(default_factory=list)
    evaluation_summary: str = Field(..., min_length=1, max_length=5000)
    metadata: GradingMetadata

    @model_validator(mode="after")
    def totals_must_be_consistent(self):
        if self.suggested_marks > self.maximum_marks + MARK_TOLERANCE:
            raise ValueError("Suggested marks exceed the maximum marks.")
        criterion_total = round(sum(c.suggested_marks for c in self.criterion_results), 2)
        if abs(criterion_total - round(self.suggested_marks, 2)) > MARK_TOLERANCE:
            raise ValueError(
                f"Criterion marks ({criterion_total}) do not sum to the suggested total ({self.suggested_marks})."
            )
        return self
