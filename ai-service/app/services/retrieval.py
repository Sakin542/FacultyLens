"""STEP 32: Retrieval / ranking support for academic document chat.

Laravel scopes and pre-ranks chunks (authorization happens there). This module provides the
shared deterministic pieces: cosine similarity, top-K selection, minimum-relevance filtering,
and a context budget so the generation prompt never receives the whole corpus.
"""

import math
import re
from typing import Any, Dict, Iterable, List, Optional, Sequence

from app.config import get_settings


def cosine_similarity(a: Sequence[float], b: Sequence[float]) -> float:
    dot = sum(x * y for x, y in zip(a, b))
    na = math.sqrt(sum(x * x for x in a))
    nb = math.sqrt(sum(y * y for y in b))
    if na == 0 or nb == 0:
        return 0.0
    return dot / (na * nb)


def rank_chunks(query_vec: Sequence[float], chunks: Iterable[Dict[str, Any]], top_k: Optional[int] = None,
                min_score: Optional[float] = None) -> List[Dict[str, Any]]:
    """Attach similarity_score to chunks that carry an `embedding`, filter and return top-K."""
    settings = get_settings()
    top_k = top_k or settings.chat_top_k
    min_score = settings.chat_min_relevance_score if min_score is None else min_score
    scored = []
    for c in chunks:
        vec = c.get("embedding")
        if not vec:
            continue
        item = dict(c)
        item["similarity_score"] = round(cosine_similarity(query_vec, vec), 4)
        item.pop("embedding", None)
        scored.append(item)
    scored.sort(key=lambda c: c["similarity_score"], reverse=True)
    return [c for c in scored if c["similarity_score"] >= min_score][:top_k]


def select_context(chunks: Sequence[Dict[str, Any]], top_k: Optional[int] = None, min_score: Optional[float] = None,
                   max_chars: Optional[int] = None) -> List[Dict[str, Any]]:
    """Filter pre-scored chunks by relevance threshold, keep top-K, and respect a character budget."""
    settings = get_settings()
    top_k = top_k or settings.chat_top_k
    min_score = settings.chat_min_relevance_score if min_score is None else min_score
    max_chars = max_chars or settings.chat_max_context_chars

    ordered = sorted(chunks, key=lambda c: float(c.get("similarity_score", 0.0)), reverse=True)
    selected: List[Dict[str, Any]] = []
    used = 0
    for c in ordered:
        if float(c.get("similarity_score", 0.0)) < min_score:
            continue
        content = str(c.get("content", "")).strip()
        if not content:
            continue
        remaining = max_chars - used
        if remaining <= 200 and selected:
            break
        if len(content) > remaining:
            content = content[: max(remaining, 200)].rstrip() + "…"
        item = dict(c)
        item["content"] = content
        selected.append(item)
        used += len(content)
        if len(selected) >= top_k:
            break
    return selected


_SENTENCE_SPLIT = re.compile(r"(?<=[.!?])\s+(?=[A-Z0-9\"'(\[])")
_STOP = {
    "a", "an", "the", "and", "or", "of", "to", "in", "on", "for", "with", "is", "are", "was", "were", "be", "it",
    "this", "that", "these", "those", "as", "at", "by", "from", "which", "what", "who", "how", "does", "do", "did",
    "can", "should", "would", "please", "explain", "describe", "list", "show", "me", "about", "tell", "give", "summarize",
}


def key_terms(text: str) -> List[str]:
    return [t for t in re.findall(r"[a-z0-9]+", text.lower()) if t not in _STOP and len(t) > 2]


def best_sentences(question: str, chunks: Sequence[Dict[str, Any]], limit: int = 4) -> List[Dict[str, Any]]:
    """Evidence sentences with the strongest lexical overlap with the question (for the extractive fallback)."""
    terms = set(key_terms(question))
    candidates = []
    for order, c in enumerate(chunks):
        sentences = [s.strip() for s in _SENTENCE_SPLIT.split(re.sub(r"\s+", " ", str(c.get("content", "")))) if s.strip()]
        for idx, s in enumerate(sentences):
            words = set(key_terms(s))
            overlap = len(terms & words)
            score = overlap + float(c.get("similarity_score", 0.0)) * 0.5 - order * 0.05
            if len(s) < 20:
                continue
            candidates.append({"sentence": s[:400], "score": score, "chunk_id": c.get("chunk_id"), "order": order, "idx": idx, "overlap": overlap})
    candidates.sort(key=lambda x: x["score"], reverse=True)
    # Sentences that share terms with the question are evidence; the rest only fill in if nothing overlaps.
    overlapping = [c for c in candidates if c["overlap"] > 0]
    pool = overlapping or candidates
    picked: List[Dict[str, Any]] = []
    seen = set()
    for cand in pool:
        if cand["sentence"] in seen:
            continue
        seen.add(cand["sentence"])
        picked.append(cand)
        if len(picked) >= limit:
            break
    return sorted(picked, key=lambda x: (x["order"], x["idx"]))
