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


_META_TERMS = {
    "summary", "summarize", "summaries", "summery", "overview", "outline", "chapter", "chapters",
    "slide", "slides", "topic", "topics", "content", "contents", "about", "discuss",
    "discusses", "covered", "main", "concept", "concepts", "syllabus", "notes", "lecture", "name", "names",
}


def is_overview_question(question: str) -> bool:
    terms = set(re.findall(r"[a-z0-9]+", question.lower()))
    return bool(terms & _META_TERMS)


_OVERVIEW_FILLER = {"document", "documents", "doc", "docs", "file", "files", "pdf", "say", "says", "uploaded", "material", "materials", "course", "cover", "covers"}


def overview_subject_supported(question: str, chunks: Sequence[Dict[str, Any]]) -> bool:
    """For overview-style questions, any subject words beyond the overview phrasing must occur in the documents.
    "Summarize the slides" has no subject (always supported); "…say about quantum computing?" needs 'quantum'/'computing'."""
    subject = {t for t in key_terms(question) if t not in _META_TERMS and t not in _OVERVIEW_FILLER}
    if not subject:
        return True
    text_terms = set(key_terms(" ".join(str(c.get("content", "")) for c in chunks)))
    return bool(subject & text_terms)


def key_terms(text: str) -> List[str]:
    return [t for t in re.findall(r"[a-z0-9]+", text.lower()) if t not in _STOP and len(t) > 2]


_BULLET_SPLIT = re.compile(r"\s*(?=[•➢✓✔▪●‣])")
_LINE_START_MARKER = re.compile(r"^(?:[•➢✓✔▪●‣]|\(?[ivx]+\)|\(?[a-z]\)|\d+[.)]|[A-Z][.)]\s)", re.IGNORECASE)
_PROB_EXPR = re.compile(r"P\s*\(([^()]{1,60})\)")
_CONTINUATION_MAX = 25
# Slide footers: dates ("12-Sep-26", "05/09/2026") and bare page numbers carry no evidence.
_FOOTER_LINE = re.compile(r"^(?:\d{1,2}[-/ ][A-Za-z]{3}[-/ ]\d{2,4}|\d{1,2}/\d{1,2}/\d{2,4}|\d{1,3})$")
# A short line without a formula that strongly matches the question is treated as a heading: the next few
# list items / statements under it are its content (e.g. "B. Specification of a Bayesian Network" → 1..4).
# A wrapped line continues the previous one: it ended mid-expression, or the next starts like a continuation.
_WRAP_END = re.compile(r"(?:[=+\-*/,(|∧∨¬≈]|\b(?:of|and|or|the|to|a|an|in|with|by|is|are|that|which))\s*$", re.IGNORECASE)
_WRAP_START = re.compile(r"^(?:[a-z(=+*/≈∧∨|]|\d)")
# "What is P(...)", "what are the probabilities", "compute/calculate …" → numeric results are evidence.
_VALUE_QUESTION = re.compile(r"\b(?:what\s+(?:is|are)\s+(?:the\s+)?(?:value|probabilit|p\()|probabilit(?:y|ies)\b|calculate|compute|how\s+(?:much|many|likely))", re.IGNORECASE)
_RESULT_LINE = re.compile(r"(?:[=≈]\s*[<⟨]?\s*\d|\d\s*%)")
_RESULT_BEFORE, _RESULT_AFTER = 3, 8   # how far (in segments) a result may sit from a strongly matching line
_HEADING_MAX = 90
_HEADING_SPAN = 4


def _normalize_expr(expr: str) -> str:
    """'P(B | a ∧ j ∧ m)' and 'P(b|a, j, m)' -> 'b|a,j,m' so notation variants match."""
    e = expr.lower().replace("∧", ",").replace("^", ",").replace(" and ", ",")
    return re.sub(r"[\s]+", "", e)


def _segments(content: str) -> List[str]:
    """Split document text into answerable units. Slides rarely end lines with '.', so newlines and
    bullet glyphs are boundaries too; tiny PDF fragments (sub/superscripts, wrapped tails) are merged back."""
    lines: List[str] = []
    for raw in str(content).replace("\r", "\n").split("\n"):
        line = re.sub(r"\s+", " ", raw).strip()
        if not line or _FOOTER_LINE.match(line):
            continue
        wrapped = bool(lines) and not _LINE_START_MARKER.match(line) and (
            _WRAP_END.search(lines[-1]) is not None or _WRAP_START.match(line) is not None
        )
        if lines and (wrapped or (len(line) <= _CONTINUATION_MAX and not _LINE_START_MARKER.match(line))):
            lines[-1] = f"{lines[-1]}{'' if len(line) <= 3 else ' '}{line}"
        else:
            lines.append(line)
    out: List[str] = []
    for line in lines:
        for part in _BULLET_SPLIT.split(line):
            out.extend(p.strip() for p in _SENTENCE_SPLIT.split(part) if p.strip())
    return out


def best_sentences(question: str, chunks: Sequence[Dict[str, Any]], limit: int = 4) -> List[Dict[str, Any]]:
    """Evidence sentences with the strongest lexical overlap with the question (for the extractive fallback).

    Besides word overlap, a probability expression from the question (e.g. P(cavity | toothache)) matching
    one in the document is strong evidence, and the line right after a strongly matching heading/statement
    (its formula, list item or result) is pulled in as supporting context."""
    terms = set(key_terms(question))
    q_exprs = {_normalize_expr(m) for m in _PROB_EXPR.findall(question)}
    wants_value = bool(_VALUE_QUESTION.search(question))
    candidates = []
    for order, c in enumerate(chunks):
        segments = _segments(c.get("content", ""))
        word_overlaps = [len(terms & set(key_terms(seg))) for seg in segments]
        strong = [i for i, n in enumerate(word_overlaps) if n >= 2]
        base = float(c.get("similarity_score", 0.0)) * 0.5 - order * 0.05
        carry = 0.0          # support passed from a strongly matching statement to the line right after it
        heading = 0.0        # support passed from a matching heading to the items listed under it
        heading_left = 0
        for idx, seg in enumerate(segments):
            overlap = word_overlaps[idx]
            expr_hit = bool(q_exprs) and any(_normalize_expr(m) in q_exprs for m in _PROB_EXPR.findall(seg))
            if expr_hit:
                overlap = max(overlap, 1)
            if wants_value and _RESULT_LINE.search(seg) and any(-_RESULT_BEFORE <= idx - s <= _RESULT_AFTER for s in strong):
                # A computed result (≈ 0.31, = 37%) in the section that matches the question answers "what is …".
                overlap = max(overlap, 1)
                result_bonus = 0.8
            else:
                result_bonus = 0.0
            follow = carry if carry and ("=" in seg or _LINE_START_MARKER.match(seg)) else 0.0
            under = heading if heading_left > 0 else 0.0
            effective = overlap or (1 if (follow or under) else 0)
            score = overlap + (3.0 if expr_hit else 0.0) + max(follow, under) + result_bonus + base

            is_heading = overlap >= 2 and len(seg) <= _HEADING_MAX and "=" not in seg
            if is_heading:
                heading, heading_left = 0.8 * overlap, _HEADING_SPAN
            elif heading_left > 0:
                heading_left -= 1
                heading *= 0.9
            carry = 0.5 * overlap if overlap >= 2 else 0.0
            if len(seg) < 12:
                continue
            candidates.append({"sentence": seg[:500], "score": score, "chunk_id": c.get("chunk_id"), "order": order, "idx": idx, "overlap": effective})
    candidates.sort(key=lambda x: x["score"], reverse=True)
    # Sentences that share terms with the question are evidence; the rest only fill in if nothing overlaps.
    overlapping = [c for c in candidates if c["overlap"] > 0]
    if overlapping:
        # Drop weak, incidental matches (one shared common word) once much stronger evidence exists.
        top = overlapping[0]["score"]
        overlapping = [c for c in overlapping if c["score"] >= top * 0.35]
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
