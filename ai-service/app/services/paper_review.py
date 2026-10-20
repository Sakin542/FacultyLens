"""Exam paper review: Bloom balance, topic coverage and duplicate questions for a whole paper.

Everything here is deterministic and reuses existing parts, so it adds no model memory:

- Bloom level per question from the faculty's own label when given, otherwise the verb-based CognitiveAnalyzer;
  the balance is measured by share of marks in three bands (lower: Remember/Understand, middle: Apply/Analyze,
  higher: Evaluate/Create) against an editable target range;
- duplicate or overlapping questions by MiniLM cosine similarity (word overlap when embeddings are unavailable);
- topic coverage by matching each topic or course outcome to its closest question (MiniLM, with a word check);
- wording problems from the question-quality rules.

The result is a checklist for the examiner. It never edits, approves or publishes a paper.
"""

import math
import re
from typing import Any, Dict, List, Optional, Tuple

from app.services.cognitive_analyzer import CognitiveAnalyzer
from app.services.feedback_drafter import _stems
from app.services.writing_tools import BLOOM_LEVELS, QuestionQualityChecker, WritingToolError

BANDS = {
    "lower": ("REMEMBER", "UNDERSTAND"),
    "middle": ("APPLY", "ANALYZE"),
    "higher": ("EVALUATE", "CREATE"),
}
BAND_LABELS = {"lower": "Remember / Understand", "middle": "Apply / Analyze", "higher": "Evaluate / Create"}
# Share of marks (%). A common guideline for undergraduate end-of-term papers; faculty can change it per review.
DEFAULT_TARGETS = {"lower": (20.0, 50.0), "middle": (30.0, 60.0), "higher": (10.0, 30.0)}

# Calibrated on MiniLM with short exam questions: a reworded duplicate ("Explain how breadth-first search works." /
# "Describe how BFS traverses a graph.") scores about 0.6, while parallel questions on different subjects
# ("... breadth-first ..." / "... depth-first ...") score higher (about 0.76), so parallel wording is filtered by words.
DUPLICATE_SIM = 0.85
OVERLAP_SIM = 0.60
LEXICAL_DUPLICATE = 0.70
LEXICAL_OVERLAP = 0.50
TOPIC_SIM = 0.60
TOPIC_SIM_WITH_WORDS = 0.38
MAX_QUESTIONS = 60
MAX_TOPICS = 30

NUMBERING = re.compile(r"^\s*(?:q(?:uestion)?\s*)?\d+\s*(?:\([a-z]\)|[a-z])?\s*[.:)\-]?\s*", re.I)


def _cosine(a: List[float], b: List[float]) -> float:
    dot = sum(x * y for x, y in zip(a, b))
    return dot / ((math.sqrt(sum(x * x for x in a)) or 1.0) * (math.sqrt(sum(y * y for y in b)) or 1.0))


def _jaccard(a: set, b: set) -> float:
    return len(a & b) / len(a | b) if a and b else 0.0


def _parallel(a: set, b: set) -> bool:
    """Same wording with a different subject ("Explain how breadth-first search works." / "... depth-first ...")."""
    return len(a & b) >= 2 and 1 <= len(a - b) <= 2 and 1 <= len(b - a) <= 2


class PaperReviewer:
    def __init__(self, hf_service: Any = None) -> None:
        self.hf = hf_service

    def _embed(self, texts: List[str]) -> Optional[List[List[float]]]:
        if self.hf is None or not texts:
            return None
        try:
            vectors = self.hf.generate_batch_embeddings(texts)
            return vectors if vectors and len(vectors) == len(texts) else None
        except Exception:
            return None

    def review(self, questions: List[Dict[str, Any]], topics: Optional[List[str]] = None,
               targets: Optional[Dict[str, Tuple[float, float]]] = None) -> Dict[str, Any]:
        items = []
        for i, q in enumerate(questions or []):
            text = NUMBERING.sub("", str(q.get("text") or "")).strip()
            if text:
                items.append({
                    "number": str(q.get("number") or i + 1),
                    "text": text,
                    "marks": float(q["marks"]) if q.get("marks") not in (None, "") else None,
                    "given_level": str(q.get("bloom_level") or "").upper() or None,
                })
        if len(items) < 2:
            raise WritingToolError("Enter at least two questions to review a paper.")
        if len(items) > MAX_QUESTIONS:
            raise WritingToolError(f"A paper review takes up to {MAX_QUESTIONS} questions.")
        topics = [t.strip() for t in (topics or []) if t and t.strip()][:MAX_TOPICS]
        targets = {**DEFAULT_TARGETS, **(targets or {})}

        # Without marks every question counts the same
        weighted = all(it["marks"] is not None and it["marks"] > 0 for it in items)
        for it in items:
            it["weight"] = it["marks"] if weighted else 1.0
        total = sum(it["weight"] for it in items)

        checker = QuestionQualityChecker(None)
        for it in items:
            if it["given_level"] in BLOOM_LEVELS:
                it["bloom_level"], it["bloom_source"] = it["given_level"], "given"
            else:
                it["bloom_level"], it["bloom_source"] = CognitiveAnalyzer.analyze(it["text"]).get("level") or "UNDERSTAND", "detected"
            it["band"] = next(b for b, levels in BANDS.items() if it["bloom_level"] in levels)
            # Short questions are normal in a paper ("Define a graph."), so only real wording problems are listed
            it["issues"] = [x for x in checker.issues(it["text"]) if x["severity"] != "low" and x["code"] != "too_short"]

        vectors = self._embed([it["text"] for it in items] + topics)
        q_vecs = vectors[: len(items)] if vectors else None
        t_vecs = vectors[len(items):] if vectors else None

        bloom = self._bloom(items, total, targets)
        duplicates = self._duplicates(items, q_vecs)
        coverage = self._topics(items, topics, q_vecs, t_vecs, total) if topics else None
        findings = self._findings(items, bloom, duplicates, coverage, weighted)

        return {
            "questions": [
                {k: it[k] for k in ("number", "text", "marks", "bloom_level", "bloom_source", "band", "issues")}
                for it in items
            ],
            "total_marks": round(total, 2) if weighted else None,
            "weighted_by": "marks" if weighted else "question count",
            "bloom": bloom,
            "duplicates": duplicates,
            "topics": coverage,
            "findings": findings,
            "similarity_method": "semantic" if q_vecs else "lexical",
            "note": "A checklist for the examiner. Bloom levels are detected from the question verbs when you do not give them; "
                    "check the cognitive demand yourself.",
        }

    # ------------------------------------------------------------------ parts

    @staticmethod
    def _bloom(items: List[Dict[str, Any]], total: float, targets: Dict[str, Tuple[float, float]]) -> Dict[str, Any]:
        levels = {lv: {"count": 0, "weight": 0.0} for lv in BLOOM_LEVELS}
        for it in items:
            levels[it["bloom_level"]]["count"] += 1
            levels[it["bloom_level"]]["weight"] += it["weight"]
        for v in levels.values():
            v["share"] = round(100 * v["weight"] / total, 1) if total else 0.0
            v["weight"] = round(v["weight"], 2)
        bands = {}
        for band, members in BANDS.items():
            share = round(sum(levels[lv]["share"] for lv in members), 1)
            low, high = targets[band]
            bands[band] = {
                "label": BAND_LABELS[band], "share": share, "target": [low, high],
                "status": "low" if share < low else ("high" if share > high else "ok"),
            }
        return {"levels": levels, "bands": bands, "missing_levels": [lv for lv, v in levels.items() if v["count"] == 0]}

    @staticmethod
    def _duplicates(items: List[Dict[str, Any]], vectors: Optional[List[List[float]]]) -> List[Dict[str, Any]]:
        out = []
        word_sets = [_stems(it["text"]) for it in items]
        for i in range(len(items)):
            for j in range(i + 1, len(items)):
                if vectors:
                    sim = _cosine(vectors[i], vectors[j])
                    kind = "duplicate" if sim >= DUPLICATE_SIM else ("overlap" if sim >= OVERLAP_SIM else None)
                    if kind == "overlap" and _parallel(word_sets[i], word_sets[j]):
                        kind = None
                else:
                    sim = _jaccard(word_sets[i], word_sets[j])
                    kind = "duplicate" if sim >= LEXICAL_DUPLICATE else ("overlap" if sim >= LEXICAL_OVERLAP else None)
                if kind:
                    out.append({"a": items[i]["number"], "b": items[j]["number"], "similarity": round(sim, 3), "kind": kind})
        return sorted(out, key=lambda d: -d["similarity"])

    @staticmethod
    def _topics(items, topics, q_vecs, t_vecs, total) -> List[Dict[str, Any]]:
        q_words = [_stems(it["text"]) for it in items]
        result = []
        for t_idx, topic in enumerate(topics):
            t_words = _stems(topic)
            matched: List[Tuple[int, float]] = []
            for q_idx, it in enumerate(items):
                shared = len(t_words & q_words[q_idx]) / len(t_words) if t_words else 0.0
                sim = _cosine(t_vecs[t_idx], q_vecs[q_idx]) if q_vecs and t_vecs else None
                # Meaning match, or a weaker meaning match backed by the topic's own words, or (no model) the words alone
                if sim is not None:
                    hit = sim >= TOPIC_SIM or (sim >= TOPIC_SIM_WITH_WORDS and shared >= 0.5)
                else:
                    hit = shared >= 0.6
                if hit:
                    matched.append((q_idx, sim if sim is not None else shared))
            weight = sum(items[q]["weight"] for q, _ in matched)
            result.append({
                "topic": topic,
                "covered": bool(matched),
                "questions": [items[q]["number"] for q, _ in sorted(matched, key=lambda m: -m[1])],
                "share": round(100 * weight / total, 1) if total else 0.0,
            })
        return result

    @staticmethod
    def _findings(items, bloom, duplicates, coverage, weighted) -> List[Dict[str, str]]:
        f: List[Dict[str, str]] = []

        def add(severity: str, message: str) -> None:
            f.append({"severity": severity, "message": message})

        unit = "marks" if weighted else "questions"
        for band, b in bloom["bands"].items():
            target = f"target {b['target'][0]:g}–{b['target'][1]:g}%"
            if b["status"] == "high":
                add("high" if band == "lower" else "medium", f"{b['share']:g}% of the {unit} test {b['label']} ({target}).")
            elif b["status"] == "low" and b["share"] == 0 and band == "higher":
                add("high", f"No question asks students to evaluate or create ({target}); consider a justify, assess or design question.")
            elif b["status"] == "low":
                add("high" if band != "lower" else "low", f"Only {b['share']:g}% of the {unit} test {b['label']} ({target}).")
        for d in duplicates:
            if d["kind"] == "duplicate":
                add("high", f"Questions {d['a']} and {d['b']} look like duplicates.")
            else:
                add("medium", f"Questions {d['a']} and {d['b']} may test the same thing; check they ask for different knowledge.")
        if coverage:
            missing = [c["topic"] for c in coverage if not c["covered"]]
            if missing:
                add("high" if len(missing) * 3 >= len(coverage) else "medium",
                    f"Not covered by any question: {'; '.join(missing[:6])}{' …' if len(missing) > 6 else ''}.")
        unclear = [it["number"] for it in items if any(x["severity"] == "high" for x in it["issues"])]
        if unclear:
            add("medium", f"Wording problems in question{'s' if len(unclear) > 1 else ''} {', '.join(unclear)} (see the question list).")
        if not weighted:
            add("low", "Marks were not given for every question, so each question counts equally.")
        order = {"high": 0, "medium": 1, "low": 2}
        return sorted(f, key=lambda x: order[x["severity"]])
