"""Health of the hosted AI provider, for the admin monitor.

Every hosted call (text chain, OCR/audio media calls, the claim judge) records its outcome here, so the backend's
five-minute monitor can see what the tools hide on purpose: when the hosted provider fails, each tool quietly falls
back to a local model. Only outcomes, safe failure reasons and times are kept (never prompts, answers or the key).

For OpenRouter, the account balance is read from its /credits endpoint (cached for ten minutes): at $0 audio is refused
and other requests can start failing at any time.
"""

import threading
import time
from collections import deque
from typing import Any, Deque, Dict, Optional, Tuple

import httpx

from app.config import get_settings

WINDOW_SECONDS = 30 * 60
CREDIT_CACHE_SECONDS = 600

_lock = threading.Lock()
_events: Deque[Tuple[float, bool, Optional[str]]] = deque(maxlen=1000)
_last_ok: Optional[float] = None
_last_error: Optional[Tuple[float, str]] = None
_credit_cache: Optional[Tuple[float, Optional[Dict[str, Any]]]] = None


def record(ok: bool, reason: Optional[str] = None) -> None:
    global _last_ok, _last_error
    now = time.time()
    with _lock:
        _events.append((now, ok, None if ok else (reason or "unknown error")))
        if ok:
            _last_ok = now
        else:
            _last_error = (now, reason or "unknown error")


def _credit() -> Optional[Dict[str, Any]]:
    """OpenRouter balance (credits bought minus used), or None when not OpenRouter or the lookup fails."""
    global _credit_cache
    s = get_settings()
    base = (s.llm_hosted_base_url or "").rstrip("/")
    key = (s.llm_hosted_api_key or "").strip()
    if "openrouter.ai" not in base or not key:
        return None
    now = time.time()
    if _credit_cache and now - _credit_cache[0] < CREDIT_CACHE_SECONDS:
        return _credit_cache[1]
    result: Optional[Dict[str, Any]] = None
    try:
        res = httpx.get(f"{base}/credits", headers={"Authorization": f"Bearer {key}"}, timeout=8)
        if res.status_code == 200:
            data = res.json().get("data") or {}
            total, used = float(data.get("total_credits") or 0), float(data.get("total_usage") or 0)
            result = {"total": round(total, 4), "used": round(used, 4), "remaining": round(total - used, 4)}
    except Exception:
        result = None
    _credit_cache = (now, result)
    return result


def snapshot() -> Dict[str, Any]:
    """Recent outcomes (last 30 minutes), last success/error and, for OpenRouter, the balance."""
    from app.services import llm_chain

    now = time.time()
    with _lock:
        recent = [e for e in _events if now - e[0] <= WINDOW_SECONDS]
        last_ok, last_error = _last_ok, _last_error
    failures = [e for e in recent if not e[1]]
    return {
        "configured": llm_chain.connection_configured() and bool(llm_chain.model_chain()),
        "models": llm_chain.model_chain(),
        "window_minutes": WINDOW_SECONDS // 60,
        "calls": len(recent),
        "failures": len(failures),
        "recent_reasons": sorted({e[2] for e in failures if e[2]})[:5],
        "last_success_seconds_ago": int(now - last_ok) if last_ok else None,
        "last_error": last_error[1] if last_error else None,
        "last_error_seconds_ago": int(now - last_error[0]) if last_error else None,
        "credit": _credit(),
    }


def reset() -> None:
    """Forget recorded outcomes and the cached balance (tests)."""
    global _last_ok, _last_error, _credit_cache
    with _lock:
        _events.clear()
        _last_ok = _last_error = None
        _credit_cache = None
