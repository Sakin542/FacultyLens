"""One-command model download: lists the models in use and keeps going when one fails."""

from app import download_models
from app.config import get_settings


def test_lists_only_models_in_use_unless_fallbacks_are_requested(monkeypatch, capsys):
    s = get_settings()
    monkeypatch.setattr(s, "claim_check_model", "cross-encoder/nli-deberta-v3-base")
    monkeypatch.setattr(s, "llm_vision_models", "google/gemini-3.8-flash")  # hosted OCR: local TrOCR is only a fallback
    assert download_models.main(["--list"]) == 0
    out = capsys.readouterr().out
    assert "cross-encoder/nli-deberta-v3-base" in out and "sentence-transformers/all-MiniLM-L6-v2" in out
    assert "trocr" not in out
    assert download_models.main(["--all", "--list"]) == 0
    assert "microsoft/trocr-base-handwritten" in capsys.readouterr().out


def test_a_failed_model_is_reported_and_the_rest_still_run(monkeypatch, capsys):
    ran = []

    def ok():
        ran.append("ok")

    def broken():
        raise OSError("no space left on device")

    monkeypatch.setattr(download_models, "_steps", lambda fallbacks: [("A", "a/model", broken), ("B", "b/model", ok)])
    assert download_models.main([]) == 1
    out = capsys.readouterr().out
    assert "FAILED: OSError: no space left on device" in out and ran == ["ok"]
    assert "1 ready, 1 failed" in out
