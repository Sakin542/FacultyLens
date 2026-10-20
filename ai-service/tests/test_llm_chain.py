"""LangChain hosted chain (app.services.llm_chain), with the HTTP calls intercepted."""

import json

import httpx
import pytest

from app.config import get_settings
from app.services import llm_chain


@pytest.fixture
def hosted(monkeypatch):
    s = get_settings()
    monkeypatch.setattr(s, "llm_hosted_base_url", "https://llm.example/v1/")
    monkeypatch.setattr(s, "llm_hosted_api_key", "secret-key-123")
    monkeypatch.setattr(s, "llm_hosted_model", "test/model-8b")
    monkeypatch.setattr(s, "llm_hosted_fallback_models", "")
    monkeypatch.setattr(s, "llm_hosted_reasoning", "")
    monkeypatch.setattr(s, "llm_hosted_max_retries", 0)
    llm_chain.reset_cache()
    yield s
    llm_chain.reset_cache()


def _route(monkeypatch, handler):
    monkeypatch.setattr(llm_chain, "_http_client", lambda: httpx.Client(transport=httpx.MockTransport(handler)))
    llm_chain.reset_cache()


def _ok(content):
    return httpx.Response(200, json={"id": "c", "object": "chat.completion", "created": 0, "model": "m",
                                     "choices": [{"index": 0, "message": {"role": "assistant", "content": content}, "finish_reason": "stop"}]})


def test_prompt_with_braces_is_sent_verbatim(monkeypatch, hosted):
    seen = {}
    _route(monkeypatch, lambda r: (seen.update(body=json.loads(r.content), url=str(r.url), headers=r.headers), _ok("  done  "))[1])
    prompt = 'Return JSON like {"questions": [{"text": "..."}]} for {topic}.'
    assert llm_chain.run_hosted(prompt, 128) == ("test/model-8b", "done")
    assert seen["url"] == "https://llm.example/v1/chat/completions"  # trailing slash in the base URL is fine
    assert seen["body"]["messages"] == [{"role": "user", "content": prompt}]
    assert seen["body"]["max_tokens"] == 128 and seen["body"]["temperature"] == 0.2
    assert seen["headers"]["x-title"] == "FacultyLens"


def test_http_error_and_empty_answer_raise_a_safe_reason(monkeypatch, hosted):
    _route(monkeypatch, lambda r: httpx.Response(401, json={"error": {"message": "bad key secret-key-123"}}))
    with pytest.raises(llm_chain.HostedCallError) as e:
        llm_chain.run_hosted("q", 32)
    assert e.value.reason == "hosted provider returned HTTP 401"  # one model: no "all N failed" prefix
    assert "secret-key-123" not in e.value.reason

    _route(monkeypatch, lambda r: _ok("   "))
    with pytest.raises(llm_chain.HostedCallError) as e:
        llm_chain.run_hosted("q", 32)
    assert "empty answer" in e.value.reason


def test_connection_failure_is_reported_without_details(monkeypatch, hosted):
    def boom(request):
        raise httpx.ConnectError("cannot reach llm.example", request=request)

    _route(monkeypatch, boom)
    with pytest.raises(llm_chain.HostedCallError) as e:
        llm_chain.run_hosted("q", 32)
    assert e.value.reason.startswith("hosted provider unavailable (")
    assert "llm.example" not in e.value.reason


def test_retries_once_on_rate_limit_when_configured(monkeypatch, hosted):
    monkeypatch.setattr(hosted, "llm_hosted_max_retries", 1)
    calls = []

    def handler(request):
        calls.append(1)
        return httpx.Response(429, headers={"retry-after-ms": "1"}, json={"error": {"message": "slow down"}}) if len(calls) == 1 else _ok("second try")

    _route(monkeypatch, handler)
    assert llm_chain.run_hosted("q", 32) == ("test/model-8b", "second try")
    assert len(calls) == 2


def _by_model(answers):
    """Handler answering per requested model: an int is an HTTP error status, a string is the answer."""
    calls = []

    def handler(request):
        model = json.loads(request.content)["model"]
        calls.append(model)
        a = answers[model]
        return httpx.Response(a, json={"error": {"message": "nope"}}) if isinstance(a, int) else _ok(a)

    return handler, calls


def test_model_chain_order_drops_blanks_and_duplicates(hosted, monkeypatch):
    monkeypatch.setattr(hosted, "llm_hosted_fallback_models", " b/two , ,test/model-8b, c/three ")
    assert llm_chain.model_chain() == ["test/model-8b", "b/two", "c/three"]


def test_falls_back_to_the_next_model_in_order(monkeypatch, hosted):
    monkeypatch.setattr(hosted, "llm_hosted_fallback_models", "b/two,c/three,d/four")
    handler, calls = _by_model({"test/model-8b": 503, "b/two": "   ", "c/three": "answer from three", "d/four": "unused"})
    _route(monkeypatch, handler)
    assert llm_chain.run_hosted("q", 32) == ("c/three", "answer from three")
    assert calls == ["test/model-8b", "b/two", "c/three"]  # empty answer also hands over; d/four never called


def test_all_models_failing_reports_count_and_first_error(monkeypatch, hosted):
    monkeypatch.setattr(hosted, "llm_hosted_fallback_models", "b/two,c/three,d/four")
    handler, calls = _by_model({"test/model-8b": 402, "b/two": 500, "c/three": 429, "d/four": 503})
    _route(monkeypatch, handler)
    with pytest.raises(llm_chain.HostedCallError) as e:
        llm_chain.run_hosted("q", 32)
    assert e.value.reason == "all 4 hosted models failed; first: hosted provider returned HTTP 402"
    assert calls == ["test/model-8b", "b/two", "c/three", "d/four"]


def test_generation_service_reports_the_model_that_answered(monkeypatch, hosted):
    from app.services import provider_context
    from app.services.generation_service import GenerationService

    monkeypatch.setattr(hosted, "llm_hosted_fallback_models", "b/two")
    _route(monkeypatch, _by_model({"test/model-8b": 500, "b/two": "fallback answer"})[0])
    gen = GenerationService()
    monkeypatch.setattr(gen, "_model_name", None)
    provider_context.set_requested_provider("hosted")
    assert gen.generate("q") == "fallback answer"
    assert gen.model_name == "hosted:b/two"
    assert gen.status()["hosted"]["fallback_models"] == ["b/two"]


def test_chains_are_cached_per_settings(hosted):
    assert llm_chain.hosted_chain(64) is llm_chain.hosted_chain(64)
    assert llm_chain.hosted_chain(64) is not llm_chain.hosted_chain(128)
