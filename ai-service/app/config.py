import os
from functools import lru_cache
from typing import Optional, Any
from pydantic import field_validator
try:
    from pydantic_settings import BaseSettings, SettingsConfigDict
except ImportError:  # pragma: no cover
    from pydantic import BaseModel as BaseSettings
    SettingsConfigDict = None


class Settings(BaseSettings):
    app_name: str = "FacultyLens AI Service"
    app_env: str = "local"
    debug: bool = True

    @field_validator("debug", mode="before")
    @classmethod
    def parse_debug_value(cls, v: Any) -> bool:
        if isinstance(v, bool):
            return v
        if isinstance(v, str):
            return v.strip().lower() in ("true", "1", "yes", "on", "debug", "t")
        return bool(v)

    ai_service_host: str = "0.0.0.0"
    ai_service_port: int = 8001

    # Hugging Face Model
    hf_model_name: str = "sentence-transformers/all-MiniLM-L6-v2"
    hf_token: Optional[str] = None
    hf_home: Optional[str] = None

    # Internal API Key for service-to-service validation
    ai_service_api_key: Optional[str] = None

    # Model settings
    max_text_length: int = 50000

    # Semantic Similarity & Duplicate Detection Settings (Step 12)
    similarity_duplicate_threshold: float = 0.85
    similarity_high_threshold: float = 0.70
    similarity_moderate_threshold: float = 0.50
    similarity_top_k: int = 5

    # Assessment Quality Engine Settings (Step 13)
    quality_weight_topic: float = 20.0
    quality_weight_lo: float = 20.0
    quality_weight_difficulty: float = 15.0
    quality_weight_cognitive: float = 15.0
    quality_weight_question_diversity: float = 15.0
    quality_weight_marks: float = 15.0

    target_easy_percent: float = 30.0
    target_medium_percent: float = 50.0
    target_hard_percent: float = 20.0

    # AI Rubric Generator Settings (Step 25)
    # MiniLM (hf_model_name) stays responsible for embeddings only. Rubric text
    # drafting may optionally use a small seq2seq model such as google/flan-t5-small.
    # Leave rubric_generation_model empty to use the structured template engine only.
    rubric_generation_enabled: bool = False
    rubric_generation_model: Optional[str] = None
    rubric_generation_max_new_tokens: int = 192
    rubric_max_criteria: int = 8

    @field_validator("rubric_generation_enabled", mode="before")
    @classmethod
    def parse_rubric_generation_enabled(cls, v: Any) -> bool:
        if isinstance(v, bool):
            return v
        if isinstance(v, str):
            return v.strip().lower() in ("true", "1", "yes", "on", "t")
        return bool(v)

    # Answer <-> Rubric Alignment Settings (Step 28)
    # Initial engineering thresholds on the combined semantic/lexical criterion signal.
    # They are deliberately separate from the STEP 11 learning-outcome thresholds and
    # MUST be validated against real faculty-reviewed examples before being trusted.
    rubric_alignment_strong_threshold: float = 0.75
    rubric_alignment_partial_threshold: float = 0.55
    rubric_alignment_weak_threshold: float = 0.35

    # Academic Document Chat (Step 32) — RAG.
    # MiniLM (hf_model_name) embeds chunks and queries; answers are produced by a SEPARATE,
    # configurable generative model. When none is configured the service falls back to an
    # extractive, evidence-only answer built from the retrieved chunks (reported honestly).
    hf_generation_model: Optional[str] = None       # e.g. google/flan-t5-base
    hf_generation_max_new_tokens: int = 256
    chat_top_k: int = 5
    chat_min_relevance_score: float = 0.35          # cosine on MiniLM; engineering threshold
    chat_max_context_chars: int = 6000
    chat_max_history_messages: int = 10
    chat_max_question_length: int = 5000
    chat_prompt_version: str = "1.0.0"

    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
        case_sensitive=False,
    )


@lru_cache()
def get_settings() -> Settings:
    """Return cached application settings instance."""
    return Settings()

