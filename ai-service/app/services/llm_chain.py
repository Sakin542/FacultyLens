"""LangChain layer for the hosted generation provider.

The hosted provider is any OpenAI-compatible chat-completions API (OpenRouter, Hugging Face Inference Providers,
Groq, ...), driven through LangChain:

    ChatPromptTemplate -> model 1 .with_fallbacks([model 2, model 3, ...]) -> (model, answer text)

LLM_HOSTED_MODEL is tried first, then each of LLM_HOSTED_FALLBACK_MODELS in order (same base URL and key). A model
that errors, times out or returns an empty answer hands over to the next one. GenerationService calls run_hosted()
and keeps its own last fallback to the local model; this module never decides which provider to use.

run_media() does the same for messages that carry images or audio (handwriting OCR, lecture transcription), over
LLM_VISION_MODELS / LLM_AUDIO_MODELS; the toolkit falls back to its local models when every hosted model fails.

The API key is read from settings here and is never logged or returned.
"""

import logging
from functools import lru_cache
from typing import Any, Dict, List, Optional

import httpx
from langchain_core.messages import AIMessage, HumanMessage
from langchain_core.prompts import ChatPromptTemplate
from langchain_core.runnables import Runnable, RunnableLambda
from langchain_openai import ChatOpenAI

from app.config import get_settings

logger = logging.getLogger("facultylens.ai")

# The prompt is passed as a variable, so braces inside it (code, JSON examples) are not read as template fields
PROMPT = ChatPromptTemplate.from_messages([("human", "{prompt}")])


class HostedCallError(Exception):
    """Every hosted model failed; reason is safe to show the administrator (no key, no URL)."""

    def __init__(self, reason: str):
        super().__init__(reason)
        self.reason = reason


class _EmptyAnswer(Exception):
    pass


def _http_client() -> Optional[httpx.Client]:
    """Hook for tests (an httpx.MockTransport client); None lets the OpenAI SDK create its own client."""
    return None


def _extra_body(max_tokens: int, reasoning: str) -> dict:
    # max_tokens goes in the body as-is: ChatOpenAI would send max_completion_tokens, which only OpenAI itself and a
    # few providers accept, while every OpenAI-compatible API understands max_tokens
    extra: dict = {"max_tokens": max_tokens}
    # OpenRouter's reasoning control; "exclude" keeps the thinking out of the answer text. Some models (Claude Opus,
    # Gemini) always reason and reject "off" with HTTP 400, so mixed chains should use an effort level instead.
    if reasoning in ("on", "off"):
        extra["reasoning"] = {"enabled": reasoning == "on", "exclude": True}
    elif reasoning in ("low", "medium", "high"):
        extra["reasoning"] = {"effort": reasoning, "exclude": True}
    return extra


def _failure(e: BaseException) -> str:
    """Short, safe description of why a model failed (never the provider's message, which may echo the request)."""
    if isinstance(e, _EmptyAnswer):
        return "returned an empty answer"
    status = getattr(e, "status_code", None)
    return f"returned HTTP {status}" if status else f"unavailable ({type(e).__name__})"


def _names(*lists: str) -> list[str]:
    """Comma-separated model lists -> one ordered list, blanks and duplicates dropped."""
    out: list[str] = []
    for name in ",".join(lists).split(","):
        name = name.strip()
        if name and name not in out:
            out.append(name)
    return out


def model_chain() -> list[str]:
    """Configured hosted text models in the order they are tried."""
    s = get_settings()
    return _names(s.llm_hosted_model or "", s.llm_hosted_fallback_models or "")


def vision_models() -> list[str]:
    """Hosted models that read images (handwriting OCR), in order."""
    return _names(get_settings().llm_vision_models or "")


def audio_models() -> list[str]:
    """Hosted models that accept audio (lecture transcription), in order."""
    return _names(get_settings().llm_audio_models or "")


def connection_configured() -> bool:
    s = get_settings()
    return bool((s.llm_hosted_base_url or "").strip() and (s.llm_hosted_api_key or "").strip())


def _candidate(name: str, base_url: str, api_key: str, max_tokens: int, timeout: int, retries: int, reasoning: str) -> Runnable:
    llm = ChatOpenAI(
        model=name,
        base_url=base_url,
        api_key=api_key,
        temperature=0.2,
        timeout=timeout,
        max_retries=retries,
        extra_body=_extra_body(max_tokens, reasoning),
        # OpenRouter attribution header; other providers ignore it
        default_headers={"X-Title": "FacultyLens"},
        http_client=_http_client(),
    )

    def run(prompt_value) -> tuple[str, str]:
        # Tags the answer with the model that produced it, and logs a failure before with_fallbacks moves on
        try:
            message: AIMessage = llm.invoke(prompt_value)
            text = (message.content if isinstance(message.content, str) else message.text).strip()
            if not text:
                raise _EmptyAnswer()
            return name, text
        except Exception as e:
            logger.warning(f"Hosted model {name} {_failure(e)}")
            raise

    return RunnableLambda(run, name=f"hosted:{name}")


@lru_cache(maxsize=16)
def _chain(models: tuple[str, ...], base_url: str, api_key: str, max_tokens: int, timeout: int, retries: int, reasoning: str) -> Runnable:
    first, *rest = [_candidate(m, base_url, api_key, max_tokens, timeout, retries, reasoning) for m in models]
    return PROMPT | (first.with_fallbacks(rest) if rest else first)


@lru_cache(maxsize=16)
def _media_chain(models: tuple[str, ...], base_url: str, api_key: str, max_tokens: int, timeout: int, retries: int, reasoning: str) -> Runnable:
    # No prompt template: callers pass ready-made messages whose content mixes text with image or audio parts
    first, *rest = [_candidate(m, base_url, api_key, max_tokens, timeout, retries, reasoning) for m in models]
    return first.with_fallbacks(rest) if rest else first


def _connection(models: List[str], max_tokens: int, timeout: int) -> tuple:
    s = get_settings()
    return (
        tuple(models),
        (s.llm_hosted_base_url or "").strip().rstrip("/"),
        (s.llm_hosted_api_key or "").strip(),
        max_tokens,
        max(5, timeout),
        max(0, s.llm_hosted_max_retries),
        (s.llm_hosted_reasoning or "").strip().lower(),
    )


def _all_failed(models: List[str], e: BaseException) -> HostedCallError:
    # with_fallbacks re-raises the first model's error once every model has failed
    reason = f"hosted provider {_failure(e)}"
    return HostedCallError(f"all {len(models)} hosted models failed; first: {reason}" if len(models) > 1 else reason)


def hosted_chain(max_tokens: int) -> Runnable:
    return _chain(*_connection(model_chain(), max_tokens, get_settings().llm_hosted_timeout_seconds))


def run_hosted(prompt: str, max_tokens: int) -> tuple[str, str]:
    """Try the hosted models in order. Returns (model that answered, answer). Raises HostedCallError."""
    models = model_chain()
    if not models:
        raise HostedCallError("no hosted model is configured")
    try:
        return hosted_chain(max_tokens).invoke({"prompt": prompt})
    except Exception as e:
        raise _all_failed(models, e) from e


def run_media(models: List[str], parts: List[Dict[str, Any]], max_tokens: int) -> tuple[str, str]:
    """Send one user message of text + image/audio parts (OpenAI chat format) to the models in order.

    Returns (model that answered, answer). Raises HostedCallError.
    """
    if not models:
        raise HostedCallError("no hosted model is configured for this tool")
    if not connection_configured():
        raise HostedCallError("hosted provider is not configured in the AI service")
    chain = _media_chain(*_connection(models, max_tokens, get_settings().llm_media_timeout_seconds))
    try:
        return chain.invoke([HumanMessage(content=parts)])
    except Exception as e:
        raise _all_failed(models, e) from e


def reset_cache() -> None:
    """Drop cached chains (after settings change, and between tests)."""
    _chain.cache_clear()
    _media_chain.cache_clear()
