"""Generation provider switching (local / hosted) and the AI toolkit endpoints, without downloading models."""

import io

import httpx
import pytest
from fastapi.testclient import TestClient

from app.config import get_settings
from app.main import app
from app.services import provider_context, toolkit
from app.services.generation_service import GenerationService, get_generation_service

client = TestClient(app)


@pytest.fixture
def hosted_settings(monkeypatch):
    s = get_settings()
    monkeypatch.setattr(s, "llm_hosted_base_url", "https://llm.example/v1")
    monkeypatch.setattr(s, "llm_hosted_api_key", "secret-key-123")
    monkeypatch.setattr(s, "llm_hosted_model", "test/model-8b")
    return s


@pytest.fixture
def no_local(monkeypatch):
    gen = get_generation_service()
    monkeypatch.setattr(gen, "_model_name", None)
    return gen


class _Resp:
    def __init__(self, status, payload):
        self.status_code = status
        self._payload = payload

    def json(self):
        return self._payload


# ------------------------------------------------------------------------------------------- provider selection

def test_header_selects_provider_and_unknown_values_fall_back_to_local():
    provider_context.set_requested_provider("hosted")
    assert provider_context.requested_provider() == "hosted"
    provider_context.set_requested_provider("something-else")
    assert provider_context.requested_provider() == "local"
    provider_context.set_requested_provider(None)
    assert provider_context.requested_provider() == "local"


def test_hosted_generation_uses_chat_completions_and_reports_the_model(monkeypatch, hosted_settings, no_local):
    calls = {}

    def fake_post(url, headers, json, timeout):
        calls.update(url=url, headers=headers, json=json)
        return _Resp(200, {"choices": [{"message": {"content": "A hosted answer."}}]})

    monkeypatch.setattr(httpx, "post", fake_post)
    provider_context.set_requested_provider("hosted")
    gen = GenerationService()

    assert gen.is_configured
    assert gen.generate("Explain BFS.") == "A hosted answer."
    assert calls["url"] == "https://llm.example/v1/chat/completions"
    assert calls["headers"]["Authorization"] == "Bearer secret-key-123"
    assert calls["json"]["model"] == "test/model-8b"
    assert gen.model_name == "hosted:test/model-8b"


def test_hosted_failure_falls_back_to_local_and_records_why(monkeypatch, hosted_settings):
    monkeypatch.setattr(httpx, "post", lambda *a, **k: _Resp(503, {}))
    gen = GenerationService()
    monkeypatch.setattr(gen, "_model_name", "local/tiny")
    monkeypatch.setattr(GenerationService, "_generate_local", lambda self, p, t, completion=False: (provider_context.record_model_used("local/tiny"), "local answer")[1])
    provider_context.set_requested_provider("hosted")
    provider_context.record_fallback(None)

    assert gen.generate("q") == "local answer"
    assert "HTTP 503" in provider_context.fallback_reason()
    assert gen.model_name == "local/tiny"


def test_hosted_selected_but_not_configured_uses_local_only(monkeypatch, no_local):
    s = get_settings()
    monkeypatch.setattr(s, "llm_hosted_api_key", None)
    provider_context.set_requested_provider("hosted")
    gen = GenerationService()
    assert gen.is_configured is False
    assert gen.generate("q") is None
    assert "not configured" in provider_context.fallback_reason()


def test_status_never_exposes_the_api_key_or_url(hosted_settings):
    status = client.get("/api/v1/toolkit/status").json()
    body = str(status)
    assert status["generation"]["hosted"] == {"configured": True, "model": "test/model-8b"}
    assert "secret-key-123" not in body and "llm.example" not in body
    assert set(status["tools"]) == {"ocr", "transcription", "translation", "speech", "writing"}


# ------------------------------------------------------------------------------------------- translation

def test_translation_validates_languages_and_length():
    r = client.post("/api/v1/toolkit/translate", json={"text": "Hello", "source": "en", "target": "en"})
    assert r.status_code == 422
    r = client.post("/api/v1/toolkit/translate", json={"text": "   ", "source": "en", "target": "bn"})
    assert r.status_code == 422 and "Enter some text" in r.json()["detail"]


def test_translation_uses_hosted_model_when_selected(monkeypatch, hosted_settings):
    monkeypatch.setattr(httpx, "post", lambda *a, **k: _Resp(200, {"choices": [{"message": {"content": "শুভ সকাল"}}]}))
    r = client.post("/api/v1/toolkit/translate", json={"text": "Good morning", "source": "en", "target": "bn"}, headers={"X-AI-Provider": "hosted"})
    assert r.status_code == 200
    assert r.json()["text"] == "শুভ সকাল"
    assert r.json()["provider"] == "hosted"


def test_translation_falls_back_to_local_nllb(monkeypatch):
    monkeypatch.setattr(toolkit.Translator, "_local", lambda self, text, s, t: f"[{s}->{t}] {text}")
    r = client.post("/api/v1/toolkit/translate", json={"text": "Good morning", "source": "en", "target": "bn"}, headers={"X-AI-Provider": "local"})
    assert r.status_code == 200
    assert r.json() | {} == r.json() and r.json()["text"] == "[en->bn] Good morning"
    assert r.json()["provider"] == "local"
    assert r.json()["model"] == get_settings().translation_model


def test_translation_chunks_keep_paragraphs():
    text = "First sentence. Second sentence.\n\nNew paragraph here."
    chunks = toolkit.Translator._chunks(text, limit=20)
    assert "\n\n" in chunks
    assert chunks[0].startswith("First sentence")


# ------------------------------------------------------------------------------------------- OCR

def _handwriting_like_page():
    from PIL import Image, ImageDraw

    img = Image.new("RGB", (600, 300), "white")
    draw = ImageDraw.Draw(img)
    for top in (40, 130, 220):  # three dark "text lines"
        draw.rectangle((50, top, 520, top + 30), fill="black")
    return img


def test_ocr_line_segmentation_finds_each_text_line():
    lines = toolkit.HandwritingOcr._lines(_handwriting_like_page())
    assert len(lines) == 3


def test_ocr_endpoint_rejects_non_images_and_reports_a_draft(monkeypatch):
    r = client.post("/api/v1/toolkit/ocr", files={"file": ("notes.png", b"not an image", "image/png")})
    assert r.status_code == 422 and "not a readable image" in r.json()["detail"]

    monkeypatch.setattr(toolkit.HandwritingOcr, "_load", lambda self: None)

    class FakeProcessor:
        def __call__(self, images, return_tensors):
            class P:
                pixel_values = None
            return P()

        def batch_decode(self, ids, skip_special_tokens):
            return ["recognised line"]

    class FakeModel:
        def generate(self, pixel_values, max_new_tokens):
            return [[1]]

    monkeypatch.setattr(toolkit.HandwritingOcr, "_processor", FakeProcessor())
    monkeypatch.setattr(toolkit.HandwritingOcr, "_model", FakeModel())
    buf = io.BytesIO()
    _handwriting_like_page().save(buf, format="PNG")
    r = client.post("/api/v1/toolkit/ocr", files={"file": ("script.png", buf.getvalue(), "image/png")})
    assert r.status_code == 200
    data = r.json()
    assert data["line_count"] == 3
    assert data["text"].count("recognised line") == 3
    assert "Draft" in data["note"]


# ------------------------------------------------------------------------------------------- transcription

def test_transcription_reports_missing_ffmpeg_instead_of_crashing(monkeypatch):
    monkeypatch.setattr(toolkit.shutil, "which", lambda name: None)
    r = client.post("/api/v1/toolkit/transcribe", files={"file": ("lecture.mp3", b"ID3....", "audio/mpeg")})
    assert r.status_code == 422 and "ffmpeg" in r.json()["detail"]


def test_transcription_enforces_the_duration_limit(monkeypatch):
    import numpy as np

    monkeypatch.setattr(toolkit.shutil, "which", lambda name: "/usr/bin/ffmpeg")
    monkeypatch.setattr("transformers.pipelines.audio_utils.ffmpeg_read", lambda data, sr: np.zeros(16000 * 10, dtype=np.float32))
    monkeypatch.setattr(get_settings(), "asr_max_seconds", 5)
    r = client.post("/api/v1/toolkit/transcribe", files={"file": ("lecture.mp3", b"audio", "audio/mpeg")})
    assert r.status_code == 422 and "limit" in r.json()["detail"]
