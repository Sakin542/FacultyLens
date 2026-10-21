"""Hosted provider health: outcomes of hosted calls and the OpenRouter balance, for the admin monitor."""

import json

import httpx
import pytest
from fastapi.testclient import TestClient

from app.config import get_settings
from app.main import app
from app.services import llm_chain, provider_health

client = TestClient(app)


@pytest.fixture
def hosted(monkeypatch):
    s = get_settings()
    monkeypatch.setattr(s, "llm_hosted_base_url", "https://openrouter.ai/api/v1")
    monkeypatch.setattr(s, "llm_hosted_api_key", "sk-or-secret-key")
    monkeypatch.setattr(s, "llm_hosted_model", "anthropic/claude-opus-5.5")
    monkeypatch.setattr(s, "llm_hosted_fallback_models", "")
    monkeypatch.setattr(s, "llm_hosted_max_retries", 0)
    monkeypatch.setattr(s, "llm_hosted_reasoning", "")
    provider_health.reset()
    llm_chain.reset_cache()
    yield s
    provider_health.reset()
    llm_chain.reset_cache()


def route(monkeypatch, status_or_text):
    def handler(request):
        if isinstance(status_or_text, int):
            return httpx.Response(status_or_text, json={"error": {"message": "This request requires more credits"}})
        return httpx.Response(200, json={"id": "c", "object": "chat.completion", "created": 0, "model": "m",
                                         "choices": [{"index": 0, "message": {"role": "assistant", "content": status_or_text}, "finish_reason": "stop"}]})
    monkeypatch.setattr(llm_chain, "_http_client", lambda: httpx.Client(transport=httpx.MockTransport(handler)))
    llm_chain.reset_cache()


def credits(monkeypatch, total=0.0, used=0.0174, status=200):
    seen = {}

    def fake_get(url, headers, timeout):
        seen.update(url=url, auth=headers.get("Authorization"))
        return httpx.Response(status, json={"data": {"total_credits": total, "total_usage": used}}, request=httpx.Request("GET", url))

    monkeypatch.setattr(provider_health.httpx, "get", fake_get)
    return seen


def test_hosted_calls_are_counted_and_the_balance_is_reported(monkeypatch, hosted):
    seen = credits(monkeypatch, total=5.0, used=1.25)
    route(monkeypatch, "fine")
    llm_chain.run_hosted("q", 32)
    route(monkeypatch, 402)
    for _ in range(2):
        with pytest.raises(llm_chain.HostedCallError):
            llm_chain.run_hosted("q", 32)

    data = client.get("/api/v1/toolkit/provider-health").json()
    assert data["configured"] is True and data["models"] == ["anthropic/claude-opus-5.5"]
    assert data["calls"] == 3 and data["failures"] == 2
    assert data["last_error"] == "hosted provider returned HTTP 402" and data["recent_reasons"] == ["hosted provider returned HTTP 402"]
    assert data["last_success_seconds_ago"] is not None
    assert data["credit"] == {"total": 5.0, "used": 1.25, "remaining": 3.75}
    assert seen["url"] == "https://openrouter.ai/api/v1/credits"
    body = json.dumps(data)
    assert "sk-or-secret-key" not in body and "openrouter.ai" not in body  # no key or URL in the report


def test_media_calls_are_counted_too(monkeypatch, hosted):
    credits(monkeypatch)
    route(monkeypatch, 500)
    with pytest.raises(llm_chain.HostedCallError):
        llm_chain.run_media(["google/gemini-3.8-flash"], [{"type": "text", "text": "read"}], 32)
    assert provider_health.snapshot()["failures"] == 1


def test_balance_is_cached_and_skipped_for_other_providers(monkeypatch, hosted):
    calls = []
    monkeypatch.setattr(provider_health.httpx, "get", lambda url, headers, timeout: (calls.append(url), httpx.Response(200, json={"data": {"total_credits": 1, "total_usage": 0}}, request=httpx.Request("GET", url)))[1])
    provider_health.snapshot()
    provider_health.snapshot()
    assert len(calls) == 1  # cached for ten minutes

    monkeypatch.setattr(hosted, "llm_hosted_base_url", "https://api.groq.com/openai/v1")
    provider_health.reset()
    assert provider_health.snapshot()["credit"] is None and len(calls) == 1


def test_balance_lookup_failure_is_not_an_error(monkeypatch, hosted):
    def boom(url, headers, timeout):
        raise httpx.ConnectError("offline")

    monkeypatch.setattr(provider_health.httpx, "get", boom)
    assert provider_health.snapshot()["credit"] is None
