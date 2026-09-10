"""STEP 32: Configurable Hugging Face generation model for grounded answers.

Deliberately separate from HuggingFaceService (MiniLM embeddings) and from the STEP 25 rubric
text generator. Reads HF_GENERATION_MODEL; when unset or unloadable the caller falls back to an
extractive answer and reports that honestly. Small seq2seq models (google/flan-t5-base) work on
CPU; causal LMs are supported through the text-generation pipeline.
"""

import logging
import os
from typing import Any, Optional

from app.config import get_settings

logger = logging.getLogger("facultylens.ai")


class GenerationService:
    _instance: Optional["GenerationService"] = None
    _pipeline: Any = None
    _task: Optional[str] = None
    _model_name: Optional[str] = None
    _is_loaded: bool = False
    _load_error: Optional[str] = None

    def __new__(cls) -> "GenerationService":
        if cls._instance is None:
            cls._instance = super().__new__(cls)
            cls._instance._init()
        return cls._instance

    def _init(self) -> None:
        settings = get_settings()
        self._model_name = (settings.hf_generation_model or "").strip() or None
        if settings.hf_home:
            os.environ.setdefault("HF_HOME", settings.hf_home)
        if settings.hf_token:
            os.environ.setdefault("HF_TOKEN", settings.hf_token)

    @property
    def is_configured(self) -> bool:
        return bool(self._model_name)

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
            from transformers import AutoConfig, pipeline

            config = AutoConfig.from_pretrained(self._model_name, token=settings.hf_token or None)
            self._task = "text2text-generation" if getattr(config, "is_encoder_decoder", False) else "text-generation"
            self._pipeline = pipeline(self._task, model=self._model_name, device=-1, token=settings.hf_token or None)
            self._is_loaded = True
            self._load_error = None
            logger.info(f"Generation model '{self._model_name}' loaded ({self._task}).")
            return True
        except Exception as e:  # pragma: no cover - environment dependent
            self._is_loaded = False
            self._load_error = str(e)
            logger.warning(f"Generation model '{self._model_name}' unavailable: {e}")
            return False

    def generate(self, prompt: str, max_new_tokens: Optional[int] = None) -> Optional[str]:
        """Return generated text or None when the model is unavailable or fails. Never raises."""
        if not self.is_configured or (not self._is_loaded and not self.load_model()):
            return None
        settings = get_settings()
        try:
            kwargs = {"max_new_tokens": max_new_tokens or settings.hf_generation_max_new_tokens, "do_sample": False}
            if self._task == "text-generation":
                kwargs["return_full_text"] = False
            outputs = self._pipeline(prompt, **kwargs)
            if outputs and isinstance(outputs, list):
                text = str(outputs[0].get("generated_text", "")).strip()
                return text or None
            return None
        except Exception as e:
            logger.warning(f"Generation model inference failed: {type(e).__name__}")
            return None


def get_generation_service() -> GenerationService:
    return GenerationService()
