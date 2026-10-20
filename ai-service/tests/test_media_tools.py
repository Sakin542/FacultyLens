"""Hosted OCR and transcription (Gemini through LangChain) with local fallbacks, Kokoro speech, bge-m3 answer
embeddings. HTTP calls to the provider are intercepted; no model is downloaded."""

import io
import json
import wave

import httpx
import numpy as np
import pytest
from fastapi.testclient import TestClient
from PIL import Image, ImageDraw

from app.config import get_settings
from app.main import app
from app.services import answer_embedder, llm_chain, provider_context, toolkit

client = TestClient(app)
HOSTED = {"X-AI-Provider": "hosted"}


@pytest.fixture
def hosted(monkeypatch):
    s = get_settings()
    monkeypatch.setattr(s, "llm_hosted_base_url", "https://llm.example/v1")
    monkeypatch.setattr(s, "llm_hosted_api_key", "secret-key-123")
    monkeypatch.setattr(s, "llm_hosted_max_retries", 0)
    monkeypatch.setattr(s, "llm_hosted_reasoning", "")
    monkeypatch.setattr(s, "llm_vision_models", "google/gemini-3.8-flash,anthropic/claude-haiku-5.5")
    monkeypatch.setattr(s, "llm_audio_models", "google/gemini-3.8-flash")
    llm_chain.reset_cache()
    yield s
    llm_chain.reset_cache()
    provider_context.set_requested_provider(None)


def route(monkeypatch, handler):
    """Send the LangChain/OpenAI SDK HTTP calls to handler(model, body) -> answer text | HTTP status int."""
    calls = []

    def transport(request):
        body = json.loads(request.content)
        calls.append(body)
        answer = handler(body["model"], body)
        if isinstance(answer, int):
            return httpx.Response(answer, json={"error": {"message": "nope"}})
        return httpx.Response(200, json={"id": "c", "object": "chat.completion", "created": 0, "model": body["model"],
                                         "choices": [{"index": 0, "message": {"role": "assistant", "content": answer}, "finish_reason": "stop"}]})

    monkeypatch.setattr(llm_chain, "_http_client", lambda: httpx.Client(transport=httpx.MockTransport(transport)))
    llm_chain.reset_cache()
    return calls


def page_png() -> bytes:
    img = Image.new("RGB", (400, 200), "white")
    ImageDraw.Draw(img).line((20, 50, 380, 50), fill="black", width=4)
    buf = io.BytesIO()
    img.save(buf, format="PNG")
    return buf.getvalue()


def no_local_ocr(monkeypatch):
    def fail(self, pages):
        raise AssertionError("local OCR must not run")
    monkeypatch.setattr(toolkit.HandwritingOcr, "_run_local", fail)


# ------------------------------------------------------------------------------------------- OCR

def test_hosted_ocr_sends_the_page_image_and_keeps_line_breaks(monkeypatch, hosted):
    no_local_ocr(monkeypatch)
    calls = route(monkeypatch, lambda model, body: "```\nBFS visits nodes\nlevel by level\n```")
    r = client.post("/api/v1/toolkit/ocr", files={"file": ("script.png", page_png(), "image/png")}, headers=HOSTED)
    assert r.status_code == 200
    data = r.json()
    assert data["provider"] == "hosted" and data["model"] == "hosted:google/gemini-3.8-flash"
    assert data["text"] == "BFS visits nodes\nlevel by level" and data["line_count"] == 2
    assert data["fallback_reason"] is None and "Draft" in data["note"]
    parts = calls[0]["messages"][0]["content"]
    assert parts[0]["type"] == "text" and "exactly as written" in parts[0]["text"]
    assert parts[1]["type"] == "image_url" and parts[1]["image_url"]["url"].startswith("data:image/jpeg;base64,")


def test_hosted_ocr_uses_the_next_vision_model_and_reports_blank_pages(monkeypatch, hosted):
    no_local_ocr(monkeypatch)
    calls = route(monkeypatch, lambda model, body: 503 if model == "google/gemini-3.8-flash" else "[blank page]")
    data = client.post("/api/v1/toolkit/ocr", files={"file": ("p.png", page_png(), "image/png")}, headers=HOSTED).json()
    assert data["model"] == "hosted:anthropic/claude-haiku-5.5"
    assert data["text"] == "" and data["line_count"] == 0
    assert [c["model"] for c in calls] == ["google/gemini-3.8-flash", "anthropic/claude-haiku-5.5"]


def test_ocr_falls_back_to_trocr_when_every_vision_model_fails(monkeypatch, hosted):
    route(monkeypatch, lambda model, body: 500)
    monkeypatch.setattr(toolkit.HandwritingOcr, "_run_local", lambda self, pages: [{"page": 1, "lines": ["local line"], "text": "local line"}])
    data = client.post("/api/v1/toolkit/ocr", files={"file": ("p.png", page_png(), "image/png")}, headers=HOSTED).json()
    assert data["provider"] == "local" and data["model"] == get_settings().ocr_model
    assert data["text"] == "local line"
    assert data["fallback_reason"] == "all 2 hosted models failed; first: hosted provider returned HTTP 500"


def test_ocr_stays_local_when_the_admin_chose_local(monkeypatch, hosted):
    calls = route(monkeypatch, lambda model, body: "should not be called")
    monkeypatch.setattr(toolkit.HandwritingOcr, "_run_local", lambda self, pages: [{"page": 1, "lines": ["x"], "text": "x"}])
    data = client.post("/api/v1/toolkit/ocr", files={"file": ("p.png", page_png(), "image/png")}, headers={"X-AI-Provider": "local"}).json()
    assert data["provider"] == "local" and data["fallback_reason"] is None and calls == []


# ------------------------------------------------------------------------------------------- transcription

@pytest.fixture
def audio(monkeypatch):
    """700 s of silence 'decoded' from any upload; MP3 encoding stubbed (no ffmpeg needed)."""
    monkeypatch.setattr(toolkit.shutil, "which", lambda name: "/usr/bin/ffmpeg")
    monkeypatch.setattr(toolkit.LectureTranscriber, "_decode", staticmethod(lambda data: np.zeros(16000 * 700, dtype=np.float32)))
    encoded = []
    monkeypatch.setattr(toolkit.LectureTranscriber, "_encode_mp3", staticmethod(lambda samples: (encoded.append(len(samples)), b"mp3-bytes")[1]))
    return encoded


def test_hosted_transcription_sends_pieces_and_puts_times_on_the_recording_clock(monkeypatch, hosted, audio):
    monkeypatch.setattr(hosted, "asr_hosted_chunk_seconds", 400)
    monkeypatch.setattr(toolkit.LectureTranscriber, "_load", lambda self: (_ for _ in ()).throw(AssertionError("Whisper must not load")))

    answers = iter(["[00:00] Today we cover graphs.\n[00:40] BFS uses a queue.", "[00:10] Questions?"])
    calls = route(monkeypatch, lambda model, body: next(answers))
    r = client.post("/api/v1/toolkit/transcribe", files={"file": ("lecture.mp3", b"audio", "audio/mpeg")}, data={"language": "bn"}, headers=HOSTED)
    assert r.status_code == 200
    data = r.json()
    assert data["provider"] == "hosted" and data["model"] == "hosted:google/gemini-3.8-flash"
    cuts = toolkit.LectureTranscriber.split_points(np.zeros(16000 * 700, dtype=np.float32), 16000, 400)
    assert len(cuts) == 3 and 300 * 16000 <= cuts[1] <= 500 * 16000          # one cut, within +-25% of 400 s
    assert sorted(audio) == sorted([cuts[1], 16000 * 700 - cuts[1]])
    assert len(calls) == 2
    part = calls[0]["messages"][0]["content"][1]
    assert part == {"type": "input_audio", "input_audio": {"data": "bXAzLWJ5dGVz", "format": "mp3"}}
    assert "spoken in Bangla" in calls[0]["messages"][0]["content"][0]["text"]
    starts = [s["start"] for s in data["segments"]]
    assert starts == sorted(starts) and data["duration_seconds"] == 700.0
    assert data["segments"][-1]["end"] == 700.0
    assert data["segments"][-1]["start"] == round(cuts[1] / 16000 + 10, 1)  # second piece starts at the cut


def test_pieces_are_cut_at_quiet_moments_near_the_target_length():
    rate = 16000
    rng = np.random.default_rng(0)
    audio = (rng.standard_normal(rate * 200) * 0.3).astype(np.float32)
    audio[int(rate * 62.0):int(rate * 63.0)] = 0.0    # a pause near the first 60 s mark
    audio[int(rate * 118.0):int(rate * 119.0)] = 0.0  # and near the second
    cuts = toolkit.LectureTranscriber.split_points(audio, rate, 60)
    assert cuts[0] == 0 and cuts[-1] == len(audio)
    assert 62.0 <= cuts[1] / rate <= 63.0 and 118.0 <= cuts[2] / rate <= 119.0
    assert all(b - a <= 75 * rate for a, b in zip(cuts, cuts[1:]))
    assert toolkit.LectureTranscriber.split_points(audio[: rate * 70], rate, 60) == [0, rate * 70]  # short: one piece


def test_transcription_falls_back_to_whisper_when_the_audio_model_fails(monkeypatch, hosted, audio):
    route(monkeypatch, lambda model, body: 429)
    monkeypatch.setattr(toolkit.LectureTranscriber, "_load", lambda self: None)
    monkeypatch.setattr(toolkit.LectureTranscriber, "_pipe", lambda self_or_audio, *a, **k: {"text": "whisper text", "chunks": [{"timestamp": (0.0, 5.0), "text": "whisper text"}]})
    data = client.post("/api/v1/toolkit/transcribe", files={"file": ("l.mp3", b"a", "audio/mpeg")}, headers=HOSTED).json()
    assert data["provider"] == "local" and data["text"] == "whisper text"
    assert data["fallback_reason"] == "hosted provider returned HTTP 429"


def test_without_audio_models_transcription_is_local_whisper_with_no_fallback_note(monkeypatch, hosted, audio):
    monkeypatch.setattr(hosted, "llm_audio_models", "")
    calls = route(monkeypatch, lambda model, body: "should not be called")
    monkeypatch.setattr(toolkit.LectureTranscriber, "_load", lambda self: None)
    monkeypatch.setattr(toolkit.LectureTranscriber, "_pipe", lambda self_or_audio, *a, **k: {"text": "whisper text", "chunks": []})
    data = client.post("/api/v1/toolkit/transcribe", files={"file": ("l.mp3", b"a", "audio/mpeg")}, headers=HOSTED).json()
    assert data["provider"] == "local" and data["model"] == get_settings().asr_model
    assert data["fallback_reason"] is None and calls == [] and audio == []
    status = client.get("/api/v1/toolkit/status", headers=HOSTED).json()["tools"]
    assert status["transcription"]["model"] == get_settings().asr_model == "openai/whisper-large-v3-turbo"


def test_segment_parsing_handles_fences_hours_continuations_and_silence():
    parse = toolkit.LectureTranscriber.parse_segments
    text = "```\n[00:05] First idea\ncontinues here\n[1:02:03] Hour mark\n[00:01] out of order\n[no speech]\n```"
    segs = parse(text, 600.0, 4400.0)
    assert segs[0] == {"start": 605.0, "end": 4323.0, "text": "First idea continues here"}
    assert segs[1]["start"] == 4323.0                    # 1:02:03 + 600 s offset
    assert segs[2]["start"] == 4323.0                    # earlier time clamped so segments stay in order
    assert segs[-1]["end"] == 4400.0
    assert parse("[no speech]", 0, 60) == []
    assert parse("no timestamps at all", 30, 90) == [{"start": 30, "end": 90, "text": "no timestamps at all"}]


# ------------------------------------------------------------------------------------------- speech

def _wav_rate(content: bytes) -> int:
    with wave.open(io.BytesIO(content)) as w:
        return w.getframerate()


@pytest.fixture
def kokoro(monkeypatch):
    class Result:
        def __init__(self, n):
            self.audio = np.full(n, 0.1, dtype=np.float32)

    class FakePipeline:
        def __init__(self):
            self.calls = []

        def __call__(self, text, voice, speed):
            self.calls.append((text, voice))
            return iter([Result(4800), Result(2400)])

    fake = FakePipeline()
    real = toolkit._module_available
    monkeypatch.setattr(toolkit, "_module_available", lambda name: True if name == "kokoro" else real(name))
    monkeypatch.setattr(toolkit.Speaker, "_kokoro", fake)
    monkeypatch.setattr(get_settings(), "tts_english_engine", "kokoro")
    return fake


def test_english_speech_uses_kokoro(kokoro):
    r = client.post("/api/v1/toolkit/speak", json={"text": "Breadth-first search uses a queue.", "language": "en"})
    assert r.status_code == 200
    assert r.headers["X-Model"] == "hexgrad/Kokoro-82M (af_heart)"
    assert _wav_rate(r.content) == 24000 and float(r.headers["X-Audio-Seconds"]) == 0.3
    assert kokoro.calls == [("Breadth-first search uses a queue.", "af_heart")]


def test_kokoro_failure_and_bangla_use_mms(monkeypatch, kokoro):
    def broken(text, voice, speed):
        raise RuntimeError("voice file missing")

    monkeypatch.setattr(toolkit.Speaker, "_kokoro", broken)
    spoken = []
    monkeypatch.setattr(toolkit.Speaker, "_speak_mms", lambda self, text, lang: (spoken.append(lang), toolkit.Speaker._wav(np.zeros(1600, np.float32), 16000, toolkit.TTS_MODELS[lang]))[1])
    r = client.post("/api/v1/toolkit/speak", json={"text": "Hello.", "language": "en"})
    assert r.status_code == 200 and r.headers["X-Model"] == "facebook/mms-tts-eng"
    r = client.post("/api/v1/toolkit/speak", json={"text": "শুভ সকাল।", "language": "bn"})
    assert r.headers["X-Model"] == "facebook/mms-tts-ben"
    assert spoken == ["en", "bn"]


def test_mms_engine_setting_skips_kokoro(monkeypatch, kokoro):
    monkeypatch.setattr(get_settings(), "tts_english_engine", "mms")
    assert toolkit.get_speaker().model_name("en") == "facebook/mms-tts-eng"


# ------------------------------------------------------------------------------------------- answer embeddings

def test_answer_embeddings_use_bge_m3_normalised_and_cached(monkeypatch):
    class FakeModel:
        def __init__(self):
            self.batches = []

        def encode(self, texts, batch_size, normalize_embeddings, convert_to_numpy):
            assert normalize_embeddings is True
            self.batches.append(list(texts))
            return np.array([[1.0, 0.0] if "graph" in t else [0.0, 1.0] for t in texts], dtype=np.float32)

    fake = FakeModel()
    monkeypatch.setattr(answer_embedder.AnswerEmbedder, "_model", fake)
    monkeypatch.setattr(answer_embedder.AnswerEmbedder, "_loaded_name", get_settings().answer_similarity_model)
    monkeypatch.setattr(answer_embedder.AnswerEmbedder, "_cache", answer_embedder.EmbeddingCache(100))

    r = client.post("/api/v1/toolkit/answer-embeddings", json={"texts": ["A graph has nodes.", "গ্রাফে নোড থাকে।", "A graph has nodes."]})
    assert r.status_code == 200
    data = r.json()
    assert data["model"] == "BAAI/bge-m3" and data["embedding_dimension"] == 2
    assert data["vectors"] == [[1.0, 0.0], [0.0, 1.0], [1.0, 0.0]]
    assert fake.batches == [["A graph has nodes.", "গ্রাফে নোড থাকে।"]]  # duplicate embedded once
    client.post("/api/v1/toolkit/answer-embeddings", json={"texts": ["A graph has nodes."]})
    assert len(fake.batches) == 1  # served from the cache


def test_answer_embeddings_fall_back_to_the_shared_model(monkeypatch):
    monkeypatch.setattr(answer_embedder.AnswerEmbedder, "_load", lambda self: False)

    class FakeHf:
        model_name = "sentence-transformers/all-MiniLM-L6-v2"

        def generate_batch_embeddings(self, texts):
            return [[0.5, 0.5] for _ in texts]

    monkeypatch.setattr(answer_embedder, "get_hf_service", lambda: FakeHf())
    data = client.post("/api/v1/toolkit/answer-embeddings", json={"texts": ["one", "two"]}).json()
    assert data["model"] == "sentence-transformers/all-MiniLM-L6-v2" and len(data["vectors"]) == 2


def test_status_lists_hosted_media_models_first_when_hosted_is_selected(hosted):
    status = client.get("/api/v1/toolkit/status", headers=HOSTED).json()["tools"]
    assert status["ocr"]["model"] == f"google/gemini-3.8-flash → anthropic/claude-haiku-5.5 → {get_settings().ocr_model}"
    assert status["transcription"]["model"] == f"google/gemini-3.8-flash → {get_settings().asr_model}"
    assert status["similarity"]["model"] == "BAAI/bge-m3"
    assert "llm.example" not in json.dumps(status) and "secret-key-123" not in json.dumps(status)
    local = client.get("/api/v1/toolkit/status", headers={"X-AI-Provider": "local"}).json()["tools"]
    assert local["ocr"]["model"] == get_settings().ocr_model
