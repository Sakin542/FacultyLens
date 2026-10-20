"""Local model memory manager: idle unloading and making room within the budget."""

import threading

import pytest
from fastapi.testclient import TestClient

from app.config import get_settings
from app.main import app
from app.services import model_memory, toolkit


@pytest.fixture
def mem(monkeypatch):
    """Fake process memory: base 1000 MB plus whatever the fake models 'hold'."""
    held = {}
    monkeypatch.setattr(model_memory, "rss_mb", lambda: 1000 + sum(held.values()))
    monkeypatch.setattr(model_memory, "_release_memory", lambda: None)
    monkeypatch.setattr(model_memory, "_start_sweeper", lambda: None)
    s = get_settings()
    monkeypatch.setattr(s, "model_memory_budget_mb", 5000)
    monkeypatch.setattr(s, "model_idle_unload_seconds", 900)
    model_memory.reset()
    yield held
    model_memory.reset()


def run(held, name, mb):
    """Use a fake model of the given size (it is "held" until unloaded)."""
    with model_memory.use(name, lambda: held.pop(name, None), mb):
        held[name] = mb


def test_idle_models_are_unloaded_after_the_timeout(mem, monkeypatch):
    clock = [100.0]
    monkeypatch.setattr(model_memory.time, "monotonic", lambda: clock[0])
    run(mem, "translation", 2600)
    clock[0] = 500.0
    run(mem, "ocr", 1400)
    assert model_memory.sweep(now=100.0 + 899) == []
    assert model_memory.sweep(now=100.0 + 900) == ["translation"]
    assert "translation" not in mem and "ocr" in mem
    assert model_memory.sweep(now=500.0 + 900) == ["ocr"] and mem == {}


def test_least_recently_used_idle_models_make_room_for_a_big_one(mem, monkeypatch):
    clock = [0.0]
    monkeypatch.setattr(model_memory.time, "monotonic", lambda: clock[0])
    for name, mb in (("translation", 2600), ("generation", 1000)):
        clock[0] += 10
        run(mem, name, mb)
    assert model_memory.rss_mb() == 4600
    clock[0] += 10
    run(mem, "transcription", 3400)  # 4600 + 3400 > 5000: oldest idle models go first
    assert set(mem) == {"transcription"}  # both had to go: 1000 + 1000 + 3400 > 5000 too
    run(mem, "speech-kokoro", 500)       # fits beside it: nothing unloaded
    assert set(mem) == {"transcription", "speech-kokoro"}


def test_a_model_in_use_is_never_unloaded(mem):
    started, release = threading.Event(), threading.Event()

    def long_job():
        with model_memory.use("transcription", lambda: mem.pop("transcription", None), 3400):
            mem["transcription"] = 3400
            started.set()
            release.wait(5)

    t = threading.Thread(target=long_job)
    t.start()
    started.wait(5)
    run(mem, "translation", 2600)  # over budget, but the only other model is busy: load anyway, with a warning
    assert model_memory.sweep(now=10**9) == ["translation"]  # idle one goes; the busy one stays
    assert "transcription" in mem
    release.set()
    t.join()
    assert model_memory.sweep(now=10**9) == ["transcription"]


def test_reusing_a_loaded_model_does_not_evict_others(mem):
    run(mem, "translation", 2600)
    run(mem, "generation", 1000)
    run(mem, "translation", 2600)  # already loaded: no room-making
    assert set(mem) == {"translation", "generation"}


def test_budget_and_timeout_can_be_switched_off(mem, monkeypatch):
    monkeypatch.setattr(get_settings(), "model_memory_budget_mb", 0)
    monkeypatch.setattr(get_settings(), "model_idle_unload_seconds", 0)
    run(mem, "a", 4000)
    run(mem, "b", 4000)
    assert set(mem) == {"a", "b"} and model_memory.sweep(now=10**9) == []


def test_a_failing_unload_does_not_break_the_request(mem):
    def bad_unload():
        raise RuntimeError("boom")

    with model_memory.use("ocr", bad_unload, 1400):
        mem["ocr"] = 1400
    assert model_memory.sweep(now=10**9) == ["ocr"]  # logged, marked unloaded, no exception


def test_toolkit_models_register_and_status_reports_them(mem, monkeypatch):
    monkeypatch.setattr(toolkit.HandwritingOcr, "_read_local", lambda self, pages: [{"page": 1, "lines": [], "text": ""}])
    toolkit.get_ocr()._run_local([])
    status = TestClient(app).get("/api/v1/toolkit/status").json()["memory"]
    assert status["budget_mb"] == 5000 and status["idle_unload_seconds"] == 900
    assert [m["name"] for m in status["loaded"]] == ["ocr"] and status["loaded"][0]["in_use"] is False
    assert model_memory.sweep(now=10**9) == ["ocr"]
    assert toolkit.HandwritingOcr._model is None and toolkit.HandwritingOcr._processor is None
