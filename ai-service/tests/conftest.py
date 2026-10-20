"""Shared test setup."""

import pytest

from app.config import get_settings


@pytest.fixture(autouse=True)
def _no_claim_check_model(monkeypatch):
    """The meaning check would download a ~0.7 GB NLI model; tests use the word check unless they inject a fake verifier."""
    monkeypatch.setattr(get_settings(), "claim_check_model", "")


@pytest.fixture(autouse=True)
def _no_online_voice(monkeypatch):
    """Bangla read-aloud would call Microsoft's online voice; tests use the local engine unless they opt in."""
    monkeypatch.setattr(get_settings(), "tts_bangla_engine", "mms")


@pytest.fixture(autouse=True)
def _no_hosted_claim_judge(monkeypatch):
    """The hosted claim judge would call OpenRouter; tests opt in by setting claim_judge_models themselves."""
    monkeypatch.setattr(get_settings(), "claim_judge_models", "")


@pytest.fixture(autouse=True)
def _no_large_local_engines(monkeypatch):
    """Where Kokoro and faster-whisper are installed (Docker, CI on Python 3.11) they would load multi-GB models; tests
    use the same engines everywhere and opt in to these by setting the engine themselves."""
    monkeypatch.setattr(get_settings(), "tts_english_engine", "mms")
    monkeypatch.setattr(get_settings(), "asr_engine", "transformers")


@pytest.fixture(autouse=True)
def _no_tts_cache(monkeypatch):
    """Read-aloud clips are cached on disk; tests that need the cache point it at a temporary folder themselves."""
    monkeypatch.setattr(get_settings(), "tts_cache_max_files", 0)
