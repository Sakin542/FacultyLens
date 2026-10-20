from typing import Any, Dict, List, Optional

from pydantic import BaseModel, Field


class ProgramOutcomeIn(BaseModel):
    code: str = Field(..., max_length=32)
    title: Optional[str] = Field(None, max_length=300)
    description: Optional[str] = Field(None, max_length=2000)


class SyllabusDraftRequest(BaseModel):
    course_code: str = Field(..., max_length=32)
    course_name: str = Field(..., min_length=2, max_length=255)
    description: str = Field("", max_length=8000)
    credits: float = Field(3, ge=0.5, le=12)
    weeks: int = Field(14, ge=6, le=20)
    outcome_count: Optional[int] = Field(None, ge=3, le=6)
    topics: Optional[List[str]] = Field(None, max_length=40)
    program_outcomes: List[ProgramOutcomeIn] = Field(default_factory=list, max_length=30)


class OutcomeIn(BaseModel):
    code: str = Field(..., max_length=32)
    description: str = Field(..., max_length=2000)
    cognitive_level: Optional[str] = Field(None, max_length=16)


class LessonPlanRequest(BaseModel):
    course_name: str = Field(..., min_length=2, max_length=255)
    outcomes: List[OutcomeIn] = Field(..., min_length=1, max_length=20)
    weeks: int = Field(14, ge=6, le=20)
    sessions_per_week: int = Field(2, ge=1, le=5)
    session_minutes: int = Field(75, ge=30, le=240)
    topics: Optional[List[str]] = Field(None, max_length=40)


class PaperSummaryRequest(BaseModel):
    text: str = Field(..., min_length=200, max_length=600000)
    title: Optional[str] = Field(None, max_length=300)
    summary_sentences: int = Field(6, ge=3, le=12)


class GenericResponse(BaseModel):
    status: str = "success"
    data: Dict[str, Any]
