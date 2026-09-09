"""Pydantic schemas for STEP 28 Answer <-> Rubric Alignment.

Alignment describes how well an answer *addresses* each rubric criterion. It is
not correctness and it is not a grade. Faculty review remains necessary.
"""

from typing import List, Optional

from pydantic import BaseModel, Field, model_validator

from app.schemas.grading import GradingAnswerIn, GradingCriterionIn
from app.schemas.rubric import CourseContext, LearningOutcomeContext

ALIGNMENT_STATUSES = ("STRONG", "PARTIAL", "WEAK", "NOT_ALIGNED")
ALIGNMENT_WEIGHTS = {"STRONG": 1.0, "PARTIAL": 0.5, "WEAK": 0.25, "NOT_ALIGNED": 0.0}
MARK_TOLERANCE = 0.005
SCORE_TOLERANCE = 0.05


class AlignmentQuestionIn(BaseModel):
    id: Optional[int] = None
    text: str = Field(..., min_length=1, max_length=10000)
    total_marks: Optional[float] = Field(default=None, gt=0, le=1000)
    question_type: Optional[str] = Field(default=None, max_length=30)
    expected_answer: Optional[str] = Field(default=None, max_length=10000)


class AlignmentRubricIn(BaseModel):
    id: Optional[int] = None
    version: Optional[int] = None
    total_marks: Optional[float] = Field(default=None, gt=0)
    criteria: List[GradingCriterionIn] = Field(..., min_length=1, max_length=12)

    @model_validator(mode="after")
    def criteria_must_be_consistent(self):
        ids = [c.id for c in self.criteria]
        if len(ids) != len(set(ids)):
            raise ValueError("Rubric criteria identifiers must be unique.")
        total = round(sum(c.max_marks for c in self.criteria), 2)
        if total <= 0:
            raise ValueError("Rubric criteria must carry marks greater than zero.")
        if self.total_marks is not None and abs(total - round(self.total_marks, 2)) > MARK_TOLERANCE:
            raise ValueError(
                f"Rubric criterion marks ({total}) do not sum to the rubric total ({self.total_marks})."
            )
        return self


class AnalyzeAnswerRubricAlignmentRequest(BaseModel):
    student_answer: GradingAnswerIn
    question: AlignmentQuestionIn
    rubric: AlignmentRubricIn
    learning_outcome: Optional[LearningOutcomeContext] = None
    course_context: Optional[CourseContext] = None


class CriterionAlignmentOut(BaseModel):
    rubric_criterion_id: int
    criterion: str = Field(..., min_length=1, max_length=255)
    max_marks: float = Field(..., ge=0)
    alignment_status: str
    alignment_score: float = Field(..., ge=0, le=1, description="Deterministic weight for the status (1 / .5 / .25 / 0)")
    similarity: float = Field(..., ge=0, le=1, description="Combined semantic/lexical criterion signal used for classification")
    evidence: List[str] = Field(default_factory=list)
    missing_elements: List[str] = Field(default_factory=list)
    explanation: str = Field(..., min_length=1, max_length=2000)

    @model_validator(mode="after")
    def status_and_weight_consistent(self):
        if self.alignment_status not in ALIGNMENT_STATUSES:
            raise ValueError(f"Unknown alignment status '{self.alignment_status}'.")
        if abs(ALIGNMENT_WEIGHTS[self.alignment_status] - self.alignment_score) > 1e-6:
            raise ValueError("alignment_score does not match the alignment_status weight.")
        return self


class AlignmentCounts(BaseModel):
    strong: int = 0
    partial: int = 0
    weak: int = 0
    not_aligned: int = 0


class AlignmentMetadata(BaseModel):
    model: str
    version: str = "1.0.0"
    embedding_model: Optional[str] = None
    generative_model_used: bool = False
    method: str = "semantic_and_rubric_alignment"
    thresholds: dict = Field(default_factory=dict)
    criteria_count: int = 0
    validation_passed: bool = True
    disclaimer: str = (
        "AI-generated rubric alignment is an assistive analysis. It may miss context, nuance, "
        "or valid alternative answers. Faculty review remains necessary."
    )


class AnalyzeAnswerRubricAlignmentResponse(BaseModel):
    status: str = "success"
    overall_alignment_score: float = Field(..., ge=0, le=100, description="Mark-weighted alignment percentage (primary)")
    unweighted_alignment_score: float = Field(..., ge=0, le=100)
    overall_alignment_status: str
    counts: AlignmentCounts
    summary: str = Field(..., min_length=1, max_length=2000)
    strengths: List[str] = Field(default_factory=list)
    missing_elements: List[str] = Field(default_factory=list)
    criterion_alignments: List[CriterionAlignmentOut] = Field(..., min_length=1)
    metadata: AlignmentMetadata

    @model_validator(mode="after")
    def scores_must_be_consistent(self):
        if self.overall_alignment_status not in ALIGNMENT_STATUSES:
            raise ValueError(f"Unknown overall alignment status '{self.overall_alignment_status}'.")
        total_marks = sum(c.max_marks for c in self.criterion_alignments)
        if total_marks > 0:
            weighted = round(sum(c.alignment_score * c.max_marks for c in self.criterion_alignments) / total_marks * 100, 2)
            if abs(weighted - self.overall_alignment_score) > SCORE_TOLERANCE:
                raise ValueError(
                    f"overall_alignment_score ({self.overall_alignment_score}) does not match the mark-weighted criterion scores ({weighted})."
                )
        unweighted = round(sum(c.alignment_score for c in self.criterion_alignments) / len(self.criterion_alignments) * 100, 2)
        if abs(unweighted - self.unweighted_alignment_score) > SCORE_TOLERANCE:
            raise ValueError("unweighted_alignment_score does not match the criterion scores.")
        return self
