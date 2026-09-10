"""STEP 32: retrieval helpers (cosine, top-K, threshold, context budget, extractive sentences)."""

from app.config import get_settings
from app.services.retrieval import best_sentences, cosine_similarity, rank_chunks, select_context


def test_cosine_similarity_basics():
    assert cosine_similarity([1, 0], [1, 0]) == 1.0
    assert cosine_similarity([1, 0], [0, 1]) == 0.0
    assert cosine_similarity([0, 0], [1, 1]) == 0.0
    assert round(cosine_similarity([1, 1], [1, 0]), 4) == 0.7071


def test_rank_chunks_orders_filters_and_limits():
    q = [1.0, 0.0, 0.0]
    chunks = [
        {"chunk_id": 1, "content": "normalization", "embedding": [0.9, 0.1, 0.0]},   # high
        {"chunk_id": 2, "content": "networking", "embedding": [0.0, 1.0, 0.0]},      # zero
        {"chunk_id": 3, "content": "keys", "embedding": [0.6, 0.6, 0.0]},            # ~0.71
        {"chunk_id": 4, "content": "no embedding"},
    ]
    ranked = rank_chunks(q, chunks, top_k=5, min_score=0.35)
    assert [c["chunk_id"] for c in ranked] == [1, 3]
    assert ranked[0]["similarity_score"] > ranked[1]["similarity_score"]
    assert "embedding" not in ranked[0]
    assert [c["chunk_id"] for c in rank_chunks(q, chunks, top_k=1, min_score=0.0)] == [1]


def test_select_context_threshold_topk_and_budget():
    chunks = [
        {"chunk_id": i, "content": "x" * 3000, "similarity_score": s} for i, s in enumerate([0.9, 0.8, 0.7, 0.2], start=1)
    ]
    selected = select_context(chunks, top_k=5, min_score=0.35, max_chars=6500)
    assert [c["chunk_id"] for c in selected] == [1, 2, 3]
    assert selected[2]["content"].endswith("…")           # truncated to budget
    assert sum(len(c["content"]) for c in selected) <= 6500 + 1
    assert select_context(chunks, top_k=2, min_score=0.35, max_chars=100000)[-1]["chunk_id"] == 2
    assert select_context([{"chunk_id": 9, "content": "x", "similarity_score": 0.1}]) == []


def test_select_context_uses_configured_defaults():
    s = get_settings()
    chunks = [{"chunk_id": i, "content": "c", "similarity_score": 0.99} for i in range(20)]
    assert len(select_context(chunks)) == s.chat_top_k


def test_best_sentences_prefers_overlap_with_question():
    chunks = [
        {"chunk_id": 1, "similarity_score": 0.8, "content": "The syllabus covers indexing strategies. Normalization reduces data redundancy in relational schemas. Exams are in December."},
        {"chunk_id": 2, "similarity_score": 0.5, "content": "Networking topics include TCP and routing protocols."},
    ]
    picked = best_sentences("What does normalization do?", chunks, limit=2)
    assert picked[0]["sentence"].startswith("Normalization reduces")
    assert picked[0]["overlap"] >= 1
    assert all(p["chunk_id"] == 1 for p in picked if p["overlap"] > 0)
