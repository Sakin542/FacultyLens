"""Optional generative text model for rubric drafting (STEP 25).

This service is deliberately separate from HuggingFaceService, which owns the
MiniLM embedding model. MiniLM is an encoder and cannot generate rubric text.

Rubric drafting can optionally use a small seq2seq Hugging Face model (e.g.
google/flan-t5-small, ~300 MB, CPU-friendly) configured through environment
variables. When no model is configured, or it fails to load, the rubric
generator falls back to its structured template engine and reports that
honestly in the response metadata.

Resource notes (CPU, no GPU required):
- google/flan-t5-small : ~300 MB download, ~1 GB RAM
- google/flan-t5-base  : ~1 GB download,   ~2.5 GB RAM
"""

import logging
import os
from typing import Any, List, Optional

from app.config import get_settings

logger = logging.getLogger("facultylens.ai")


class TextGenerationService:
    _instance: Optional["TextGenerationService"] = None
    _pipeline: Any = None
    _model_name: Optional[str] = None
    _is_loaded: bool = False
    _load_error: Optional[str] = None

    def __new__(cls) -> "TextGenerationService":
        if cls._instance is None:
            cls._instance = super(TextGenerationService, cls).__new__(cls)
            cls._instance._init_service()
        return cls._instance

    def _init_service(self) -> None:
        settings = get_settings()
        self._model_name = (settings.rubric_generation_model or "").strip() or None
        if settings.hf_home:
            os.environ.setdefault("HF_HOME", settings.hf_home)
        if settings.hf_token:
            os.environ.setdefault("HF_TOKEN", settings.hf_token)

    @property
    def is_configured(self) -> bool:
        return bool(self._model_name) and get_settings().rubric_generation_enabled

    @property
    def is_loaded(self) -> bool:
        return self._is_loaded

    @property
    def model_name(self) -> Optional[str]:
        return self._model_name

    @property
    def load_error(self) -> Optional[str]:
        return self._load_error

    def load_model(self) -> bool:
        if not self.is_configured:
            return False
        if self._is_loaded and self._pipeline is not None:
            return True

        settings = get_settings()
        try:
            logger.info(f"Loading rubric generation model: {self._model_name}...")
            from transformers import pipeline

            self._pipeline = pipeline(
                "text2text-generation",
                model=self._model_name,
                device=-1,
                token=settings.hf_token or None,
            )
            self._is_loaded = True
            self._load_error = None
            logger.info(f"Rubric generation model '{self._model_name}' loaded.")
            return True
        except Exception as e:  # pragma: no cover - depends on environment
            self._is_loaded = False
            self._load_error = str(e)
            logger.warning(f"Rubric generation model '{self._model_name}' unavailable: {e}")
            return False

    def generate_lines(self, prompt: str, max_new_tokens: Optional[int] = None) -> List[str]:
        """Generate text for a prompt and return non-empty lines. Returns [] on failure."""
        if not self.is_configured:
            return []
        if not self._is_loaded and not self.load_model():
            return []

        settings = get_settings()
        try:
            outputs = self._pipeline(
                prompt,
                max_new_tokens=max_new_tokens or settings.rubric_generation_max_new_tokens,
                do_sample=False,
                num_beams=2,
            )
            text = ""
            if outputs and isinstance(outputs, list):
                text = str(outputs[0].get("generated_text", ""))
            lines = [line.strip(" -•*\t") for line in text.replace(";", "\n").split("\n")]
            return [line for line in lines if line]
        except Exception as e:
            logger.warning(f"Rubric generation model inference failed: {e}")
            return []


def get_text_generation_service() -> TextGenerationService:
    return TextGenerationService()
