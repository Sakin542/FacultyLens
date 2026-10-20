"""Per-request AI provider selection.

Laravel sends ``X-AI-Provider: local|hosted`` (chosen by the administrator in Platform settings). A small ASGI
middleware stores it in a context variable for the duration of the request so GenerationService can pick the
provider without every endpoint passing it through. The model actually used is recorded the same way so callers
can report it honestly.
"""

from contextvars import ContextVar
from typing import Optional

PROVIDERS = ("local", "hosted")

_requested: ContextVar[str] = ContextVar("ai_provider_requested", default="local")
_used_model: ContextVar[Optional[str]] = ContextVar("ai_model_used", default=None)
_fallback: ContextVar[Optional[str]] = ContextVar("ai_provider_fallback", default=None)


def requested_provider() -> str:
    return _requested.get()


def set_requested_provider(value: Optional[str]) -> None:
    value = (value or "").strip().lower()
    _requested.set(value if value in PROVIDERS else "local")


def record_model_used(name: Optional[str]) -> None:
    _used_model.set(name)


def model_used() -> Optional[str]:
    return _used_model.get()


def record_fallback(reason: Optional[str]) -> None:
    _fallback.set(reason)


def fallback_reason() -> Optional[str]:
    return _fallback.get()


class ProviderHeaderMiddleware:
    """Pure ASGI middleware (context variables set here are visible to the endpoint and its threadpool)."""

    def __init__(self, app):
        self.app = app

    async def __call__(self, scope, receive, send):
        if scope.get("type") == "http":
            header = None
            for key, value in scope.get("headers") or []:
                if key == b"x-ai-provider":
                    header = value.decode("latin-1")
                    break
            set_requested_provider(header)
            record_model_used(None)
            record_fallback(None)
        await self.app(scope, receive, send)
