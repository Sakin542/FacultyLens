"""Performance and singleton model initialization tests for FacultyLens AI microservice."""

import time
from app.services.huggingface_service import get_hf_service


def test_model_is_singleton():
    """Verify that get_hf_service returns the exact same loaded model instance without reloading."""
    svc1 = get_hf_service()
    svc2 = get_hf_service()
    assert svc1 is svc2
    assert svc1._model is svc2._model
    assert svc1.is_loaded is True


def test_embedding_generation_performance():
    """Verify that embedding generation is fast and within acceptable latency limits (< 500ms for short academic text)."""
    svc = get_hf_service()
    text = "Explain the concepts of Third Normal Form (3NF) and Boyce-Codd Normal Form (BCNF) in relational DBMS."

    start = time.perf_counter()
    emb = svc.generate_embedding(text)
    duration = time.perf_counter() - start

    assert isinstance(emb, list)
    assert len(emb) == 384  # all-MiniLM-L6-v2 dimension
    assert duration < 0.50, f"Embedding generation took {duration:.3f}s, expected < 0.50s"
