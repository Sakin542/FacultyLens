"""STEP 43 — the embedding LRU cache must change latency only, never results."""

import numpy as np
import pytest

from app.services.huggingface_service import EmbeddingCache, get_hf_service


def test_cache_hit_returns_the_identical_vector():
    hf = get_hf_service()
    hf.cache.clear()
    texts = ["Explain B+ tree indexing.", "Apply normalization to 3NF.", "Explain B+ tree indexing."]
    first = hf.generate_batch_embeddings(texts)
    second = hf.generate_batch_embeddings(texts)
    assert first == second
    assert first[0] == first[2], "duplicate texts inside one batch share one vector"
    assert hf.generate_embedding(texts[1]) == first[1]
    stats = hf.cache.stats()
    assert stats["hits"] >= 4 and stats["entries"] == 2


def test_cache_miss_and_hit_mix_preserves_order():
    hf = get_hf_service()
    hf.cache.clear()
    a = hf.generate_batch_embeddings(["alpha question", "beta question"])
    mixed = hf.generate_batch_embeddings(["gamma question", "beta question", "alpha question", "delta question"])
    assert mixed[1] == a[1] and mixed[2] == a[0]
    assert len(mixed) == 4 and all(len(v) == hf.embedding_dimension for v in mixed)
    fresh_gamma = hf.generate_batch_embeddings(["gamma question"])[0]
    assert fresh_gamma == mixed[0]


def test_cached_vectors_match_a_direct_model_encode():
    hf = get_hf_service()
    hf.cache.clear()
    text = "Evaluate transaction isolation levels."
    via_cache = np.asarray(hf.generate_batch_embeddings([text])[0])
    direct = hf._model.encode([text], convert_to_numpy=True)[0]
    assert np.allclose(via_cache, direct, atol=1e-6)
    cos = float(np.dot(via_cache, direct) / (np.linalg.norm(via_cache) * np.linalg.norm(direct)))
    assert cos == pytest.approx(1.0, abs=1e-6)


def test_cache_is_bounded_lru():
    c = EmbeddingCache(max_entries=3)
    c.put_many({"a": [1.0], "b": [2.0], "c": [3.0]})
    c.get_many(["a"])  # a becomes most recent
    c.put_many({"d": [4.0]})
    assert set(c.get_many(["a", "b", "c", "d"]).keys()) == {"a", "c", "d"}
    disabled = EmbeddingCache(max_entries=0)
    disabled.put_many({"x": [1.0]})
    assert disabled.get_many(["x"]) == {}
