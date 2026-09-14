"""STEP 46: Shared AI safety primitives.

Deterministic, dependency-free helpers used by the chat, generation and explanation
pipelines and by the safety test suite:

* prompt-injection detection (document text and user questions are treated as data;
  detection only annotates and logs — it never blocks legitimate document content)
* secret / system-prompt leak detection for model output
* unsupported-certainty detection (the AI must not present hedged evidence as fact)
* hallucinated-confidence detection ("99% confident", "100% accurate")
* fabricated-citation detection for [S#] references
* conflicting numeric evidence detection across retrieved chunks
* log scrubbing so safety events never carry secrets or student content

Nothing here calls a model. Nothing here is a substitute for Laravel authorization.
"""

import logging
import re
from typing import Any, Dict, Iterable, List, Optional, Sequence

logger = logging.getLogger("facultylens.ai.safety")

# ------------------------------------------------------------------ event codes

EVENT_PROMPT_INJECTION_DETECTED = "AI_PROMPT_INJECTION_BLOCKED"
EVENT_CITATION_VALIDATION_FAILED = "AI_CITATION_VALIDATION_FAILED"
EVENT_GROUNDING_FAILED = "AI_GROUNDING_FAILED"
EVENT_OUTPUT_VALIDATION_FAILED = "AI_OUTPUT_VALIDATION_FAILED"
EVENT_HALLUCINATION_DETECTED = "AI_HALLUCINATION_DETECTED"
EVENT_CONFLICTING_EVIDENCE = "AI_CONFLICTING_EVIDENCE_DETECTED"
EVENT_SECRET_LEAK_BLOCKED = "AI_SECRET_LEAK_BLOCKED"  # noqa: S105 - event name, not a credential

# ------------------------------------------------------------------ user-facing texts

CONFLICTING_EVIDENCE_TEXT = (
    "Conflicting evidence detected. The available documents disagree on this point, so FacultyLens "
    "cannot determine a single answer. Faculty review required."
)
CONFIDENCE_UNAVAILABLE_TEXT = "Confidence unavailable."

# ------------------------------------------------------------------ patterns

# Direct + indirect injection cues. Deliberately broad: a hit only *annotates* the request.
INJECTION_PATTERNS = re.compile(
    r"("
    r"ignore\s+(all\s+|any\s+|the\s+)?(previous|prior|above|earlier|preceding)\s+(instructions?|prompts?|rules?)"
    r"|disregard\s+(all\s+|the\s+)?(previous|prior|system)\s+(instructions?|prompts?|rules?)"
    r"|forget\s+(all\s+|your\s+)?(previous|prior)\s+instructions?"
    r"|reveal\s+(the\s+|your\s+)?(system|hidden|secret|internal)\s+(prompt|instructions?|configuration)"
    r"|(show|print|display|output|dump|tell)\s+(me\s+)?(the\s+|your\s+)?(system\s+prompt|hidden\s+instructions?|internal\s+(laravel\s+)?configuration)"
    r"|\bapi[_\s-]?key\b"
    r"|\bhf_token\b"
    r"|\bsecret\s+token\b"
    r"|\bsystem\s+prompt\b"
    r"|you\s+are\s+now\s+(a|an|the|in)\b"
    r"|act\s+as\s+(a|an|the)\s+\w+\s+(and\s+)?(ignore|reveal|bypass)"
    r"|when\s+this\s+document\s+is\s+(analyzed|analysed|processed|read)"
    r"|send\s+(all\s+)?(the\s+)?(database|student|user)\s+(records?|data|information)"
    r"|return\s+all\s+student\s+(information|records|data)"
    r"|mark\s+(every|all|each)\s+answers?\s+as\s+correct"
    r"|(grant|give)\s+(full\s+|all\s+)?marks\s+to\s+(every|all)"
    r"|bypass\s+(the\s+)?(authorization|authorisation|authentication|permission)"
    r"|<<<\s*(system|end\s+system)"
    r"|\bsudo\s+mode\b|\bjailbreak\b|\bdeveloper\s+mode\b"
    r")",
    re.IGNORECASE,
)

# Anything that looks like credentials, secrets or the private system prompt.
SECRET_LEAK_PATTERNS = re.compile(
    r"("
    r"<<<SYSTEM INSTRUCTIONS>>>"
    r"|You are FacultyLens Academic Document Assistant"
    r"|\bhf_[A-Za-z0-9]{12,}"
    r"|\bsk-[A-Za-z0-9]{16,}"
    r"|\bAI_SERVICE_API_KEY\b"
    r"|\bAPP_KEY\s*[:=]"
    r"|\bDB_PASSWORD\s*[:=]"
    r"|\bapi[_ ]?key\s*[:=]\s*\S+"
    r"|\bBearer\s+[A-Za-z0-9._\-]{16,}"
    r"|\bpassword\s*[:=]\s*\S+"
    r")",
    re.IGNORECASE,
)

# Statements FacultyLens must never make without deterministic evidence.
UNSUPPORTED_CERTAINTY_PATTERNS = re.compile(
    r"("
    r"\bdefinitely\b|\bcertainly\b|\bundoubtedly\b|\bwithout\s+(any\s+)?doubt\b|\bunquestionably\b"
    r"|\bexact\s+duplicate\b|\bidentical\s+question\b"
    r"|\bthe\s+student\s+(has\s+)?(definitely\s+)?(failed|passed)\b"
    r"|\bstudents?\s+will\s+fail\b"
    r"|\bgraded\s+incorrectly\b|\bmarked\s+incorrectly\b|\bfaculty\s+(member\s+)?(was|is)\s+wrong\b"
    r"|\bviolates?\s+(the\s+)?(accreditation|university|institutional|regulatory|legal)\s+(requirements?|polic(y|ies)|standards?|regulations?)\b"
    r"|\b(is|are)\s+(fully\s+)?compliant\s+with\s+(the\s+)?(accreditation|university|institutional|legal|regulatory)\b"
    r"|\bguaranteed\b|\bproves?\s+that\b|\bit\s+is\s+(a\s+)?fact\s+that\b"
    r"|\bthis\s+(question|assessment|exam)\s+is\s+(definitely\s+)?(unfair|invalid|illegal)\b"
    r")",
    re.IGNORECASE,
)

# Numeric confidence / accuracy claims produced by free text rather than a calibrated evaluator.
HALLUCINATED_CONFIDENCE_PATTERNS = re.compile(
    r"("
    r"\b(9\d|100)\s?%\s*(accurate|accuracy|confident|confidence|certain|certainty|correct|sure)\b"
    r"|\b(accurate|confident|certain|correct|sure)\s+(to|at|with)\s+(9\d|100)\s?%"
    r"|\b(100|99|98|97|96|95)\s?(percent|per\s+cent)\s+(accurate|confident|certain|correct|sure)\b"
    r"|\bconfidence\s*(:|=|of)\s*(0?\.9\d|1\.0+|9\d\s?%|100\s?%)"
    r"|\b(fully|completely|totally|absolutely)\s+(accurate|certain|confident|correct)\b"
    r")",
    re.IGNORECASE,
)

_CITATION = re.compile(r"\[S(\d+)\]")

# Numeric fact extraction for conflict detection: "<label> (=|:|is|are|of) <number>".
_NUMERIC_FACT = re.compile(
    r"(?P<label>[A-Za-z][A-Za-z\s\-/]{2,60}?)\s*(?:=|:|is|are|equals?|of|will\s+be|shall\s+be)\s*"
    r"(?P<value>\d+(?:\.\d+)?)\s*(?P<unit>%|percent|marks?|points?|weeks?|days?|hours?|minutes?|credits?)?",
    re.IGNORECASE,
)
_LABEL_STOP = {"the", "a", "an", "this", "that", "and", "or", "of", "for", "in", "on", "to", "with", "is", "are", "total"}

_SCRUB_PATTERNS = [
    (re.compile(r"\bhf_[A-Za-z0-9]{6,}", re.IGNORECASE), "hf_[redacted]"),
    (re.compile(r"\bsk-[A-Za-z0-9]{6,}"), "sk-[redacted]"),
    (re.compile(r"(api[_ ]?key\s*[:=]\s*)\S+", re.IGNORECASE), r"\1[redacted]"),
    (re.compile(r"(password\s*[:=]\s*)\S+", re.IGNORECASE), r"\1[redacted]"),
    (re.compile(r"(Bearer\s+)[A-Za-z0-9._\-]{8,}", re.IGNORECASE), r"\1[redacted]"),
    (re.compile(r"\b\d{1,3}(\.\d{1,3}){3}\b"), "[ip]"),
    (re.compile(r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}"), "[email]"),
]


# ------------------------------------------------------------------ detection helpers


def detect_prompt_injection(text: Optional[str]) -> Dict[str, Any]:
    """Return {"detected": bool, "matches": [lower-cased distinct cue strings]} for *any* text."""
    if not text:
        return {"detected": False, "matches": []}
    found = []
    for m in INJECTION_PATTERNS.finditer(text):
        cue = re.sub(r"\s+", " ", m.group(0).strip().lower())
        if cue not in found:
            found.append(cue)
    return {"detected": bool(found), "matches": found[:10]}


def scan_chunks_for_injection(chunks: Iterable[Dict[str, Any]]) -> List[int]:
    """Chunk ids whose content contains instruction-like text. Content is NOT returned or logged."""
    flagged: List[int] = []
    for c in chunks:
        if detect_prompt_injection(str(c.get("content", "")))["detected"]:
            cid = c.get("chunk_id")
            if cid is not None:
                flagged.append(int(cid))
    return flagged


def contains_secret_leak(text: Optional[str]) -> bool:
    return bool(text) and bool(SECRET_LEAK_PATTERNS.search(text))


def find_unsupported_certainty(text: Optional[str]) -> List[str]:
    if not text:
        return []
    found: List[str] = []
    for m in UNSUPPORTED_CERTAINTY_PATTERNS.finditer(text):
        phrase = re.sub(r"\s+", " ", m.group(0).strip().lower())
        if phrase not in found:
            found.append(phrase)
    return found


def find_hallucinated_confidence(text: Optional[str]) -> List[str]:
    if not text:
        return []
    found: List[str] = []
    for m in HALLUCINATED_CONFIDENCE_PATTERNS.finditer(text):
        phrase = re.sub(r"\s+", " ", m.group(0).strip().lower())
        if phrase not in found:
            found.append(phrase)
    return found


def validate_confidence(value: Any) -> Optional[float]:
    """Normalise a model-produced confidence into [0, 1] or return None (→ 'Confidence unavailable')."""
    if isinstance(value, bool) or value is None:
        return None
    try:
        v = float(value)
    except (TypeError, ValueError):
        return None
    if v != v or v in (float("inf"), float("-inf")):  # NaN / inf
        return None
    # Percent-scale values are only accepted when unambiguous (>= 5); 1 < v < 5 cannot be told
    # apart from an out-of-range probability and is rejected.
    if 5.0 <= v <= 100.0:
        v = v / 100.0
    if v < 0.0 or v > 1.0:
        return None
    return round(v, 4)


def validate_citations(text: Optional[str], context_size: int) -> Dict[str, Any]:
    """Check every [S#] reference in *text* points at a real context block (1..context_size)."""
    if not text:
        return {"cited": [], "valid": [], "invalid": [], "fabricated": False}
    cited = sorted({int(m) for m in _CITATION.findall(text)})
    valid = [n for n in cited if 1 <= n <= context_size]
    invalid = [n for n in cited if n not in valid]
    return {"cited": cited, "valid": valid, "invalid": invalid, "fabricated": bool(cited) and not valid}


def _normalise_label(label: str) -> str:
    """Subject key = the last significant (singularised) word, e.g. 'final examination total marks' -> 'mark'."""
    words = [w for w in re.findall(r"[a-z]+", label.lower()) if w not in _LABEL_STOP]
    if not words:
        return ""
    return words[-1].rstrip("s")


def extract_numeric_facts(text: str) -> List[Dict[str, Any]]:
    facts: List[Dict[str, Any]] = []
    for m in _NUMERIC_FACT.finditer(text or ""):
        label = _normalise_label(m.group("label"))
        if len(label) < 3:
            continue
        unit = (m.group("unit") or "").lower().rstrip("s")
        facts.append({"label": label, "value": float(m.group("value")), "unit": unit})
    return facts


def detect_conflicting_evidence(chunks: Sequence[Dict[str, Any]], question: Optional[str] = None) -> List[Dict[str, Any]]:
    """
    Find numeric statements about the same subject that disagree across *different* chunks.

    Returns [{"subject": "marks", "values": [{"chunk_id", "document_id", "document_name", "value", "unit"}...]}].
    When *question* is given, only subjects that share a key term with it are reported so an
    unrelated conflict elsewhere in the corpus does not block an answer.
    """
    by_label: Dict[str, List[Dict[str, Any]]] = {}
    for c in chunks:
        for f in extract_numeric_facts(str(c.get("content", ""))):
            by_label.setdefault(f["label"], []).append({
                "chunk_id": c.get("chunk_id"),
                "document_id": c.get("document_id"),
                "document_name": c.get("document_name"),
                "value": f["value"],
                "unit": f["unit"],
            })

    q_terms = {t.rstrip("s") for t in re.findall(r"[a-z]{3,}", (question or "").lower())}
    conflicts: List[Dict[str, Any]] = []
    for label, entries in by_label.items():
        values = {e["value"] for e in entries}
        chunk_ids = {e["chunk_id"] for e in entries}
        if len(values) < 2 or len(chunk_ids) < 2:
            continue
        if q_terms and label not in q_terms:
            continue
        conflicts.append({"subject": label, "values": entries})
    return conflicts


def scrub_for_log(text: Optional[str], limit: int = 200) -> str:
    """Remove secrets / identifiers before a string may enter a log line."""
    out = str(text or "")
    for pattern, repl in _SCRUB_PATTERNS:
        out = pattern.sub(repl, out)
    return out[:limit]


def log_safety_event(event: str, **fields: Any) -> None:
    """Structured safety event. Callers must only pass counts, ids and flags — never content or secrets."""
    safe_fields = {k: (scrub_for_log(v) if isinstance(v, str) else v) for k, v in fields.items()}
    logger.warning("%s %s", event, safe_fields)
