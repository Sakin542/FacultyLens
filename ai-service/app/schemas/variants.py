from typing import List, Literal, Optional

from pydantic import BaseModel, Field


class QuestionVariantsRequest(BaseModel):
    question_text: str = Field(..., min_length=3, max_length=5000)
    expected_answer: Optional[str] = Field(None, max_length=10000)
    count: int = Field(2, ge=1, le=5)
    mode: Literal["numbers", "wording", "both"] = "both"
    seed: Optional[int] = Field(None, ge=0, le=2_147_483_647)


class NumberChange(BaseModel):
    from_: str = Field(..., alias="from")
    to: str

    model_config = {"populate_by_name": True}


class QuestionVariant(BaseModel):
    question_text: str
    expected_answer: Optional[str] = None
    methods: List[str]
    number_changes: List[NumberChange]
    answer_needs_review: bool
    warnings: List[str]
    similarity: Optional[float] = None


class QuestionVariantsResponse(BaseModel):
    status: str = "success"
    original: str
    mode: str
    variants: List[QuestionVariant]
    engine: str
    model: Optional[str] = None
    message: Optional[str] = None
