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

