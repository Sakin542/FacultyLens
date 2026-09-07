"""Pydantic schemas for STEP 15 Unified Assessment Analysis API.

Defines the consolidated request and response payloads for end-to-end examination
analysis orchestrating Question Analysis, LO Alignment, Semantic Similarity,
Assessment Quality Engine, and AI Recommendations.
"""

from typing import List, Optional, Dict, Any, Union
from pydantic import BaseModel, Field, field_validator


class UnifiedQuestionInput(BaseModel):
    id: Optional[Union[int, str]] = None
    number: Optional[Union[int, str]] = None
    text: str = Field(..., description="The complete text of the question")
    marks: Optional[float] = Field(default=1.0, description="Marks allocated for this question")
    question_type: Optional[str] = Field(default=None, description="Format (MCQ, Descriptive, etc.)")
    difficulty: Optional[str] = Field(default=None, description="Difficulty level (Easy, Medium, Hard)")
    cognitive_level: Optional[str] = Field(default=None, description="Bloom's taxonomy cognitive level")
    topics: Optional[List[str]] = Field(default_factory=list, description="Associated or tagged course topics")
    learning_outcome_code: Optional[str] = Field(default=None, description="Pre-assigned or mapped LO code")
    similarity_score: Optional[float] = Field(default=None, description="Highest similarity score to past questions")
    is_duplicate: Optional[bool] = Field(default=False, description="Flagged as potential duplicate")

    @field_validator("text")
    @classmethod
    def validate_text(cls, v: str) -> str:
        if not v or not v.strip():
            raise ValueError("Question text cannot be empty.")
        return v.strip()


class UnifiedLOInput(BaseModel):
    id: Optional[Union[int, str]] = None
    code: Optional[str] = None
    description: str = Field(..., description="Full text description of the learning outcome")
    weight: Optional[float] = None


class UnifiedPrevQuestionInput(BaseModel):
    id: Optional[Union[int, str]] = None
    number: Optional[Union[int, str]] = None
    text: str = Field(..., description="The complete text of the historical question")
    assessment_title: Optional[str] = None
    term: Optional[str] = None
    year: Optional[Union[int, str]] = None


class UnifiedAssessmentMeta(BaseModel):
    id: Optional[Union[int, str]] = None
    title: Optional[str] = None
    total_marks: Optional[float] = None
    course_code: Optional[str] = None
    course_title: Optional[str] = None


class UnifiedAssessmentAnalysisRequest(BaseModel):
    course_id: Optional[Union[int, str]] = None
    course_name: Optional[str] = None
    assessment: Optional[UnifiedAssessmentMeta] = None
    questions: List[UnifiedQuestionInput] = Field(..., min_length=1, description="List of examination questions to analyze")
    learning_outcomes: Optional[List[UnifiedLOInput]] = Field(default_factory=list, description="Course learning outcomes")
    course_topics: Optional[List[str]] = Field(default_factory=list, description="Syllabus topics for topic extraction")
    previous_questions: Optional[List[UnifiedPrevQuestionInput]] = Field(default_factory=list, description="Historical exam question bank")
    weights: Optional[Dict[str, float]] = None
    difficulty_targets: Optional[Dict[str, float]] = None
    custom_rules: Optional[Dict[str, Any]] = None


class UnifiedAssessmentAnalysisResponse(BaseModel):
    status: str = "success"
    method: str = "unified_assessment_analysis_pipeline"
    assessment_id: Optional[Union[int, str]] = None
    course_id: Optional[Union[int, str]] = None
    questions_analysis: Optional[Dict[str, Any]] = None
    alignment_analysis: Optional[Dict[str, Any]] = None
    similarity_analysis: Optional[Dict[str, Any]] = None
    quality_analysis: Optional[Dict[str, Any]] = None
    recommendations: Optional[Dict[str, Any]] = None
    summary: Optional[Dict[str, Any]] = None

