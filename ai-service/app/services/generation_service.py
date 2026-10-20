"""STEP 32: Configurable generation model for grounded answers, question drafts, syllabus drafts and variants.

Deliberately separate from HuggingFaceService (MiniLM embeddings) and from the STEP 25 rubric text generator.

Two providers, chosen per request by the administrator (see provider_context):
- local  : HF_GENERATION_MODEL loaded in this container. Seq2seq (google/flan-t5-*) and causal instruct models
           (e.g. Qwen/Qwen2.5-0.5B-Instruct) are supported; instruct models get their chat template.
- hosted : an OpenAI-compatible /chat/completions endpoint (LLM_HOSTED_BASE_URL + LLM_HOSTED_MODEL, key in
           LLM_HOSTED_API_KEY). If the hosted call fails, the local model is used and the fallback is recorded.

When nothing is available the callers fall back to their extractive/template engines and report that honestly.
generate() never raises.
"""

import logging
import os
from typing import Any, Optional

import httpx

from app.config import get_settings
from app.services import provider_context

logger = logging.getLogger("facultylens.ai")


class GenerationService:
    _instance: Optional["GenerationService"] = None
    _pipeline: Any = None
    _model: Any = None
    _tokenizer: Any = None
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

    # ------------------------------------------------------------------------------------------- status

    @property
    def local_configured(self) -> bool:
        return bool(self._model_name)

    @property
    def hosted_configured(self) -> bool:
        s = get_settings()
        return bool((s.llm_hosted_base_url or "").strip() and (s.llm_hosted_model or "").strip() and (s.llm_hosted_api_key or "").strip())

    @property
    def hosted_model_name(self) -> Optional[str]:
        return (get_settings().llm_hosted_model or "").strip() or None

    def _use_hosted(self) -> bool:
        return provider_context.requested_provider() == "hosted" and self.hosted_configured

    @property
    def is_configured(self) -> bool:
        return self.local_configured or self._use_hosted()

    @property
    def is_loaded(self) -> bool:
        return self._is_loaded

    @property
    def model_name(self) -> Optional[str]:
        """The model that produced the most recent output in this request, else the one that would be used."""
        used = provider_context.model_used()
        if used:
            return used
        return f"hosted:{self.hosted_model_name}" if self._use_hosted() else self._model_name

    @property
    def local_model_name(self) -> Optional[str]:
        return self._model_name

    @property
    def load_error(self) -> Optional[str]:
        return self._load_error

    # ------------------------------------------------------------------------------------------- local model

    def load_model(self) -> bool:
        if not self.local_configured:
            return False
        if self._is_loaded and (self._pipeline is not None or self._model is not None):
            return True
        settings = get_settings()
        try:
            from transformers import AutoConfig, AutoTokenizer, AutoModelForSeq2SeqLM, pipeline

            config = AutoConfig.from_pretrained(self._model_name, token=settings.hf_token or None)
            if getattr(config, "is_encoder_decoder", False):
                self._tokenizer = AutoTokenizer.from_pretrained(self._model_name, token=settings.hf_token or None)
                self._model = AutoModelForSeq2SeqLM.from_pretrained(self._model_name, token=settings.hf_token or None)
                self._task = "seq2seq"
            else:
                self._task = "text-generation"
                self._pipeline = pipeline("text-generation", model=self._model_name, device=-1, token=settings.hf_token or None)
                self._tokenizer = self._pipeline.tokenizer

            self._is_loaded = True
            self._load_error = None
            logger.info(f"Generation model '{self._model_name}' loaded ({self._task}).")
            return True
        except Exception as e:  # pragma: no cover - environment dependent
            self._is_loaded = False
            self._load_error = str(e)
            logger.warning(f"Generation model '{self._model_name}' unavailable: {e}")
            return False

    def _generate_local(self, prompt: str, tokens: int, completion: bool = False) -> Optional[str]:
        if not self.local_configured or (not self._is_loaded and not self.load_model()):
            return None
        try:
            if self._task == "seq2seq" and self._model is not None and self._tokenizer is not None:
                inputs = self._tokenizer(prompt, return_tensors="pt", truncation=True, max_length=1024)
                outputs = self._model.generate(**inputs, max_new_tokens=tokens, do_sample=False)
                text = self._tokenizer.decode(outputs[0], skip_special_tokens=True).strip()
            elif self._pipeline is not None:
                text_in = prompt
                # Instruct/chat models answer far better when the prompt is wrapped in their chat template. Few-shot
                # "continue this pattern" prompts (completion=True) must stay raw, or the model replies conversationally.
                if not completion and getattr(self._tokenizer, "chat_template", None):
                    text_in = self._tokenizer.apply_chat_template([{"role": "user", "content": prompt}], tokenize=False, add_generation_prompt=True)
                outputs = self._pipeline(text_in, max_new_tokens=tokens, do_sample=False, return_full_text=False)
                text = str(outputs[0].get("generated_text", "")).strip() if outputs and isinstance(outputs, list) else ""
            else:
                return None
            if text:
                provider_context.record_model_used(self._model_name)
            return text or None
        except Exception as e:
            logger.warning(f"Local generation failed: {type(e).__name__}")
            return None

    # ------------------------------------------------------------------------------------------- hosted model

    def _generate_hosted(self, prompt: str, tokens: int) -> Optional[str]:
        s = get_settings()
        url = (s.llm_hosted_base_url or "").rstrip("/") + "/chat/completions"
        try:
            res = httpx.post(
                url,
                headers={"Authorization": f"Bearer {s.llm_hosted_api_key}", "Content-Type": "application/json"},
                json={"model": s.llm_hosted_model, "messages": [{"role": "user", "content": prompt}], "max_tokens": tokens, "temperature": 0.2},
                timeout=max(5, s.llm_hosted_timeout_seconds),
            )
            if res.status_code >= 400:
                provider_context.record_fallback(f"hosted provider returned HTTP {res.status_code}")
                logger.warning(f"Hosted generation returned HTTP {res.status_code}")
                return None
            content = (((res.json().get("choices") or [{}])[0].get("message") or {}).get("content") or "").strip()
            if content:
                provider_context.record_model_used(f"hosted:{s.llm_hosted_model}")
                return content
            provider_context.record_fallback("hosted provider returned an empty answer")
            return None
        except Exception as e:  # network errors, timeouts, malformed JSON
            provider_context.record_fallback(f"hosted provider unavailable ({type(e).__name__})")
            logger.warning(f"Hosted generation failed: {type(e).__name__}")
            return None

    # ------------------------------------------------------------------------------------------- public API

    def generate(self, prompt: str, max_new_tokens: Optional[int] = None, completion: bool = False) -> Optional[str]:
        """Return generated text or None when no provider produced output. Never raises.

        completion=True continues the prompt as plain text (few-shot patterns) on local causal models; hosted chat APIs
        always receive the prompt as a user message.
        """
        tokens = max_new_tokens or get_settings().hf_generation_max_new_tokens
        if self._use_hosted():
            text = self._generate_hosted(prompt, tokens)
            if text:
                return text
        elif provider_context.requested_provider() == "hosted":
            provider_context.record_fallback("hosted provider is not configured in the AI service")
        return self._generate_local(prompt, tokens, completion)

    def status(self) -> dict:
        """Safe status for the admin panel (never includes the API key or URL)."""
        return {
            "requested_provider": provider_context.requested_provider(),
            "local": {"configured": self.local_configured, "model": self._model_name, "loaded": self._is_loaded, "error": "load_failed" if self._load_error else None},
            "hosted": {"configured": self.hosted_configured, "model": self.hosted_model_name},
        }


def get_generation_service() -> GenerationService:
    return GenerationService()
