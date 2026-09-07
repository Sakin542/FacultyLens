from typing import List, Optional, Dict, Any, Union
from pydantic import BaseModel, Field


class CurrentQuestionItem(BaseModel):
    id: Optional[Union[int, str]] = Field(None, description="Current question identifier")
    question_number: Optional[Union[int, str]] = Field(None, description="Question number / label")
    text: str = Field(..., min_length=1, description="Question text")
    question_type: Optional[str] = Field(None, description="Question classification type")
    cognitive_level: Optional[str] = Field(None, description="Bloom's cognitive level")
    topics: Optional[List[str]] = Field(default_factory=list, description="Associated topics")


class PreviousQuestionItem(BaseModel):
    id: Optional[Union[int, str]] = Field(None, description="Previous question identifier")
    text: str = Field(..., min_length=1, description="Previous question text")
    source_year: Optional[Union[int, str]] = Field(None, description="Historical exam year")
    source_assessment: Optional[str] = Field(None, description="Historical assessment title")
    question_type: Optional[str] = Field(None, description="Historical question type")
    cognitive_level: Optional[str] = Field(None, description="Historical cognitive level")


class SimilarityThresholdsConfig(BaseModel):
    duplicate: float = Field(0.85, description="Threshold for POTENTIAL_DUPLICATE (>= duplicate)")
    high: float = Field(0.70, description="Threshold for HIGHLY_SIMILAR (>= high and < duplicate)")
    moderate: float = Field(0.50, description="Threshold for SOMEWHAT_SIMILAR (>= moderate and < high)")
    top_k: int = Field(5, description="Maximum number of historical matches to return per question")


class AnalyzeSimilarityRequest(BaseModel):
    course_id: Optional[int] = Field(None, description="Associated course ID")
    current_questions: List[CurrentQuestionItem] = Field(..., min_length=1, description="Current examination questions")
    previous_questions: List[PreviousQuestionItem] = Field(default_factory=list, description="Historical course question bank")
    thresholds: Optional[SimilarityThresholdsConfig] = Field(None, description="Custom similarity thresholds")
    top_k: Optional[int] = Field(None, description="Override top-K matches per question")


class MatchedPreviousQuestion(BaseModel):
    previous_question_id: Optional[Union[int, str]] = None
    previous_question_text: str
    similarity_score: float = Field(..., description="Cosine similarity score (0.0 to 1.0)")
    similarity_status: str = Field(..., description="'POTENTIAL_DUPLICATE', 'HIGHLY_SIMILAR', 'SOMEWHAT_SIMILAR', or 'NOT_SIMILAR'")
    source_year: Optional[Union[int, str]] = None
    source_assessment: Optional[str] = None
    question_type: Optional[str] = None
    cognitive_level: Optional[str] = None


class QuestionSimilarityResult(BaseModel):
    current_question_id: Optional[Union[int, str]] = None
    current_question_number: Optional[Union[int, str]] = None
    current_question_text: str
    current_question_type: Optional[str] = None
    current_cognitive_level: Optional[str] = None
    max_similarity_score: float = Field(0.0, description="Highest similarity score across all historical questions")
    max_similarity_status: str = Field("NOT_SIMILAR", description="Status of the highest match")
    matches: List[MatchedPreviousQuestion] = Field(default_factory=list, description="Top-K ranked previous question matches")
    reasoning: Optional[str] = None


class AnalyzeSimilarityResponse(BaseModel):
    status: str = "success"
    method: str = "semantic_embedding_cosine_similarity"
    model: str = "sentence-transformers/all-MiniLM-L6-v2"
    thresholds: Dict[str, float]
    total_current_questions: int
    total_previous_questions: int
    potential_duplicates_count: int
    highly_similar_count: int
    somewhat_similar_count: int
    average_similarity_score: float
    results: List[QuestionSimilarityResult]
    findings: List[str]

