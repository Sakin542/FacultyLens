"""STEP 45: AI explainability helpers.

Two responsibilities, both deterministic:

1. ``explain_question`` — re-runs the STEP 10 *rule tables* (question type, difficulty, Bloom, topic)
   against the question text and returns the cue words / factors that fired. It never invents evidence:
   every excerpt is a literal substring of the question. When a stored label is supplied it also reports
   whether the current text still produces that label (``consistent``), so stale labels are disclosed.

2. ``validate_explanation`` — a contradiction check for *free-text* explanations (LLM-generated or
   otherwise) against the structured facts they describe. If the text mentions a score, label, LO code,
   marks value or similarity that does not match the facts, or uses forbidden black-box wording, the text is
   rejected and callers fall back to a deterministic explanation.

Nothing here exposes prompts, model internals or hidden reasoning — only observable rule matches.
"""

from __future__ import annotations

import re
from typing import Any, Dict, Iterable, List, Optional, Sequence

from app.config import get_settings
from app.services.cognitive_analyzer import CognitiveAnalyzer
from app.services.difficulty_analyzer import DifficultyAnalyzer
from app.services.prompt_builder import PromptBuilder
from app.services.question_classifier import QuestionClassifier
from app.services.retrieval import key_terms

RULE_VERSION = "step10-rules-1.0.0"
EXPLANATION_VERSION = "1.0.0"

QUESTION_TYPE_RULES = [
    ("TRUE_FALSE", QuestionClassifier.TRUE_FALSE_PATTERN, "true/false wording"),
    ("MCQ", QuestionClassifier.MCQ_PATTERN, "option-selection wording"),
    ("PROBLEM_SOLVING", QuestionClassifier.PROBLEM_SOLVING_PATTERN, "calculation / code directive"),
    ("ANALYTICAL", QuestionClassifier.ANALYTICAL_PATTERN, "analytical directive"),
    ("SHORT_ANSWER", QuestionClassifier.SHORT_ANSWER_PATTERN, "brevity indicator"),
    ("CONCEPTUAL", QuestionClassifier.CONCEPTUAL_PATTERN, "definitional directive"),
    ("DESCRIPTIVE", QuestionClassifier.DESCRIPTIVE_PATTERN, "explanatory directive"),
]

DIFFICULTY_RULES = [
    ("HARD", DifficultyAnalyzer.HARD_CUES, "hard cue"),
    ("MEDIUM", DifficultyAnalyzer.MEDIUM_CUES, "medium cue"),
    ("EASY", DifficultyAnalyzer.EASY_CUES, "easy cue"),
]

BLOOM_LEVELS = ["REMEMBER", "UNDERSTAND", "APPLY", "ANALYZE", "EVALUATE", "CREATE"]
DIFFICULTY_LEVELS = ["EASY", "MEDIUM", "HARD"]
SIMILARITY_LABELS = ["POTENTIAL_DUPLICATE", "HIGHLY_SIMILAR", "SOMEWHAT_SIMILAR", "NOT_SIMILAR"]
ALIGNMENT_LABELS = ["STRONG", "WEAK", "NOT_ALIGNED"]

# Wording that presents AI output as certain truth or exposes internals; never allowed in an explanation.
FORBIDDEN_PHRASES = [
    "exact duplicate",
    "100% certain",
    "100% accurate",
    "the model thought",
    "the model thinks",
    "neural network knows",
    "the ai understands",
    "the ai knows",
    "system prompt",
    "chain of thought",
    "chain-of-thought",
    "api key",
    "hf_token",
    "students will fail",
    "you must",
]

LIMITATIONS = {
    "question_type": ["Question-type classification is rule-based and depends on the wording of the question."],
    "difficulty": ["Difficulty is an AI-assisted estimate and may vary by learner population and course context."],
    "cognitive_level": ["Bloom classification can involve expert judgment; the detected verb may not reflect the full task demand."],
    "topics": ["Topic detection depends on the course topics defined for the course and the wording of the question."],
}

_SENTENCE_RE = re.compile(r"(?<=[.!?])\s+")
_NUMBER_RE = re.compile(r"(?<![\w.])(\d+(?:\.\d+)?)(\s*%|\s*/\s*\d+(?:\.\d+)?)?")


def sanitize_untrusted_text(text: Optional[str], max_len: int = 300) -> str:
    """Neutralise delimiter spoofing and clamp; document/answer text is data, never instructions."""
    if not text:
        return ""
    cleaned = PromptBuilder.sanitize_context(str(text))
    cleaned = re.sub(r"\s+", " ", cleaned).strip()
    if len(cleaned) > max_len:
        cleaned = cleaned[: max_len - 1].rstrip() + "…"
    return cleaned


def contains_instruction_like_text(text: Optional[str]) -> bool:
    return PromptBuilder.contains_injection(text or "")


def _matches(pattern: re.Pattern, text: str, label: str, limit: int = 6) -> List[Dict[str, Any]]:
    out: List[Dict[str, Any]] = []
    seen = set()
    for m in pattern.finditer(text):
        excerpt = m.group(0)
        key = excerpt.lower()
        if key in seen:
            continue
        seen.add(key)
        out.append({"type": "question_text", "text": excerpt, "label": label, "position": m.start()})
        if len(out) >= limit:
            break
    return out


def _normalize(label: Optional[str]) -> Optional[str]:
    if label is None:
        return None
    return str(label).strip().upper().replace(" ", "_").replace("-", "_") or None


def explain_question_type(text: str, stored_label: Optional[str] = None) -> Dict[str, Any]:
    result = QuestionClassifier.classify(text)
    detected = result["question_type"]
    cues: List[Dict[str, Any]] = []
    for label, pattern, desc in QUESTION_TYPE_RULES:
        if label == detected:
            cues = _matches(pattern, text, desc)
            break
    stored = _normalize(stored_label) or detected
    if cues:
        summary = f"FacultyLens classified this question as {stored} because its wording contains {cues[0]['label']} such as \"{cues[0]['text']}\"."
    else:
        summary = f"FacultyLens classified this question as {stored} based on its overall wording and length; no specific directive verb matched a more specific type."
    return {
        "label": stored,
        "detected_label": detected,
        "consistent": stored == detected,
        "method": "RULE_BASED",
        "confidence": {"available": result.get("confidence") is not None, "value": result.get("confidence")},
        "evidence": cues,
        "factors": ["Directive verbs and answer-format wording", "Question length"],
        "summary": summary,
    }


def explain_difficulty(text: str, stored_label: Optional[str] = None) -> Dict[str, Any]:
    result = DifficultyAnalyzer.analyze(text)
    detected = result["level"]
    stored = _normalize(stored_label) or detected
    words = text.split()
    cues: List[Dict[str, Any]] = []
    counts: Dict[str, int] = {}
    for label, pattern, desc in DIFFICULTY_RULES:
        found = _matches(pattern, text, desc)
        counts[label.lower() + "_cue_count"] = len(pattern.findall(text))
        cues.extend(found)
    has_subclauses = bool(re.search(r"[;\n]|\band\s+also\b|\bmoreover\b|\bfurthermore\b", text, re.I))
    factors = {
        "word_count": len(words),
        "has_subclauses": has_subclauses,
        **counts,
    }
    reasons: List[str] = []
    if counts.get("hard_cue_count"):
        reasons.append("it uses wording associated with multi-step or design-level reasoning")
    if counts.get("medium_cue_count") and not counts.get("hard_cue_count"):
        reasons.append("it asks for explanation, comparison or application")
    if counts.get("easy_cue_count") and not counts.get("hard_cue_count"):
        reasons.append("it contains recall-style wording")
    if len(words) > 30:
        reasons.append("the question is long (more than 30 words)")
    elif len(words) < 10:
        reasons.append("the question is short (fewer than 10 words)")
    if has_subclauses and len(words) > 20:
        reasons.append("it contains multiple sub-clauses")
    reason_text = "; ".join(reasons) if reasons else "no strong difficulty cues were found, so the default estimate applies"
    return {
        "label": stored,
        "detected_label": detected,
        "consistent": stored == detected,
        "method": "RULE_BASED",
        "confidence": {"available": False, "value": None},
        "evidence": cues,
        "factors": factors,
        "factor_names": ["Cue words for reasoning depth", "Question length", "Sub-clause structure"],
        "summary": f"FacultyLens estimated the difficulty as {stored} because {reason_text}.",
    }


def explain_cognitive_level(text: str, stored_label: Optional[str] = None) -> Dict[str, Any]:
    result = CognitiveAnalyzer.analyze(text)
    detected = result["level"]
    stored = _normalize(stored_label) or detected
    leading = False
    cues: List[Dict[str, Any]] = []
    for level, pattern in CognitiveAnalyzer.LEADING_VERB_PATTERNS:
        m = pattern.search(text)
        if m and level == detected:
            leading = True
            verb = m.group(0).strip()
            verb = re.sub(r"^(?:Q\d+[\.:\)]?|\d+[\.:\)])\s*", "", verb)
            cues.append({"type": "question_text", "text": verb, "label": "leading directive verb", "position": m.start()})
            break
    pattern = CognitiveAnalyzer.BLOOM_PATTERNS.get(detected)
    if pattern is not None:
        for c in _matches(pattern, text, f"{detected.title()}-level verb"):
            if not any(x["text"].lower() == c["text"].lower() for x in cues):
                cues.append(c)
    if cues:
        listed = ", ".join(f"\"{c['text']}\"" for c in cues[:3])
        summary = f"FacultyLens classified this question at the {stored} level because it uses {stored.title()}-level directive wording ({listed})."
    else:
        summary = f"FacultyLens classified this question at the {stored} level from its question phrasing; no explicit Bloom directive verb was found, so the default level applies."
    return {
        "label": stored,
        "detected_label": detected,
        "consistent": stored == detected,
        "method": "RULE_BASED",
        "confidence": {"available": False, "value": None},
        "evidence": cues,
        "leading_verb": leading,
        "factors": ["Leading directive verb", "Bloom-level verb table (Revised Bloom's Taxonomy)"],
        "summary": summary,
    }


def explain_topics(text: str, topics: Optional[Sequence[str]], course_topics: Optional[Sequence[str]]) -> Dict[str, Any]:
    labels = [str(t) for t in (topics or []) if str(t).strip()]
    lowered = text.lower()
    matched_terms: List[Dict[str, Any]] = []
    for topic in labels:
        for term in key_terms(topic) or [topic.lower()]:
            idx = lowered.find(term.lower())
            if idx >= 0 and not any(m["text"].lower() == term.lower() for m in matched_terms):
                matched_terms.append({"type": "question_text", "text": text[idx: idx + len(term)], "label": f"term related to '{topic}'", "position": idx})
    method = "EMBEDDING_BASED" if course_topics else "RULE_BASED"
    context = "course topics defined for this course" if course_topics else "keywords extracted from the question text"
    if labels:
        summary = f"FacultyLens detected the topic(s) {', '.join(labels[:3])} by comparing the question with {context}."
    else:
        summary = "No topic was detected for this question."
    return {
        "labels": labels,
        "method": method,
        "evidence": matched_terms[:8],
        "context": context,
        "summary": summary,
    }


def explain_question(
    question_text: str,
    question_type: Optional[str] = None,
    difficulty_level: Optional[str] = None,
    cognitive_level: Optional[str] = None,
    topics: Optional[Sequence[str]] = None,
    course_topics: Optional[Sequence[str]] = None,
    embedding_model: Optional[str] = None,
) -> Dict[str, Any]:
    text = (question_text or "").strip()
    untrusted = contains_instruction_like_text(text)
    if embedding_model is None and course_topics:
        embedding_model = get_settings().hf_model_name
    return {
        "status": "success",
        "question_type": explain_question_type(text, question_type),
        "difficulty": explain_difficulty(text, difficulty_level),
        "cognitive_level": explain_cognitive_level(text, cognitive_level),
        "topics": explain_topics(text, topics, course_topics),
        "model": {"rule_version": RULE_VERSION, "embedding_model": embedding_model},
        "limitations": LIMITATIONS,
        "explanation_version": EXPLANATION_VERSION,
        "untrusted_content_detected": untrusted,
    }


# ----------------------------------------------------------------------------- validation


def _numbers_in(text: str) -> List[float]:
    values: List[float] = []
    for m in _NUMBER_RE.finditer(text):
        try:
            values.append(float(m.group(1)))
        except ValueError:
            continue
    return values


def _approximately_in(value: float, allowed: Iterable[float]) -> bool:
    for a in allowed:
        if abs(value - a) <= 0.5 + 1e-9:  # tolerate display rounding (0.815 → 0.82, 71.6 → 72)
            return True
        if a <= 1.0 and abs(value - a * 100) <= 0.5 + 1e-9:  # 0.82 shown as 82%
            return True
    return False


def validate_explanation(text: Optional[str], facts: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
    """Return {'valid': bool, 'violations': [...]} for a free-text explanation vs structured facts.

    facts = {
      'scores': {'similarity': 0.88, 'overall_quality_score': 72},   # every number in text must match one
      'labels': {'cognitive_level': 'ANALYZE', 'difficulty': 'HARD', 'similarity_status': 'POTENTIAL_DUPLICATE'},
      'allowed_numbers': [10, 4, 3],                                  # e.g. marks values that may appear
      'codes': ['LO2', 'CO2', 'PO3'],                                 # outcome codes that may appear
    }
    """
    facts = facts or {}
    violations: List[str] = []
    if text is None or not str(text).strip():
        return {"valid": False, "violations": ["Explanation is empty."]}
    body = str(text)
    lowered = body.lower()

    for phrase in FORBIDDEN_PHRASES:
        if phrase in lowered:
            violations.append(f"Contains forbidden wording: '{phrase}'.")
    if contains_instruction_like_text(body) or "<<<" in body or ">>>" in body:
        violations.append("Contains instruction-like or delimiter content.")

    scores = {k: float(v) for k, v in (facts.get("scores") or {}).items() if v is not None}
    allowed_numbers = [float(v) for v in (facts.get("allowed_numbers") or []) if v is not None]
    if scores or allowed_numbers:
        allowed = list(scores.values()) + allowed_numbers
        for value in _numbers_in(body):
            if not _approximately_in(value, allowed):
                violations.append(f"Mentions number {value:g} which does not match any known score or value.")

    labels = {k: _normalize(v) for k, v in (facts.get("labels") or {}).items() if v}
    vocabularies = {
        "cognitive_level": BLOOM_LEVELS,
        "difficulty": DIFFICULTY_LEVELS,
        "similarity_status": SIMILARITY_LABELS,
        "alignment": ALIGNMENT_LABELS,
    }
    for key, vocab in vocabularies.items():
        actual = labels.get(key)
        if not actual:
            continue
        actual_human = actual.replace("_", " ").lower()
        for candidate in vocab:
            if candidate == actual:
                continue
            human = candidate.replace("_", " ").lower()
            mentioned = re.search(rf"\b{re.escape(human)}\b", lowered) is not None
            if not mentioned:
                continue
            actual_mentioned = re.search(rf"\b{re.escape(actual_human)}\b", lowered) is not None
            # A competing label is a contradiction when it is asserted as the result, or when the real label is absent.
            if _label_asserted(lowered, human) or not actual_mentioned:
                violations.append(f"States {key} '{candidate}' but the actual value is '{actual}'.")

    codes = [str(c).upper() for c in (facts.get("codes") or [])]
    if codes:
        for m in re.finditer(r"\b((?:LO|CO|PO|CLO|PLO)\s?-?\d+)\b", body, re.I):
            code = re.sub(r"[\s-]", "", m.group(1)).upper()
            if code not in [re.sub(r"[\s-]", "", c) for c in codes]:
                violations.append(f"References outcome code {m.group(1)} which is not part of this result.")

    return {"valid": not violations, "violations": violations}


def _label_asserted(lowered: str, human_label: str) -> bool:
    """True when the text asserts a *different* label as the result (e.g. 'classified as easy')."""
    verbs = r"(?:is|as|level[:\s]+|difficulty[:\s]+|classified\s+as|rated\s+as|status[:\s]+)"
    return bool(re.search(rf"{verbs}\s*(?:an?\s+)?{re.escape(human_label)}\b", lowered))


def deterministic_fallback(kind: str, facts: Dict[str, Any]) -> str:
    """Plain-language explanation built only from facts; used when a generated explanation fails validation."""
    labels = facts.get("labels") or {}
    scores = facts.get("scores") or {}
    if kind == "similarity":
        score = scores.get("similarity")
        status = _normalize(labels.get("similarity_status")) or "NOT_SIMILAR"
        return (f"FacultyLens measured a semantic similarity of {score:.2f} / 1.00 between these questions, which falls in the "
                f"{status.replace('_', ' ').title()} band. Similarity does not prove the questions are identical.") if score is not None else \
               f"FacultyLens classified this pair as {status.replace('_', ' ').title()} based on the configured similarity thresholds."
    if kind == "alignment":
        score = scores.get("similarity")
        status = _normalize(labels.get("alignment")) or "NOT_ALIGNED"
        return f"FacultyLens measured a similarity of {score:.2f} between the question and the learning outcome, which the configured thresholds classify as {status.replace('_', ' ')}."
    if kind == "quality":
        score = scores.get("overall_quality_score")
        return f"The overall quality score is {score:g}, a weighted combination of the available quality dimensions." if score is not None else \
            "The overall quality score is a weighted combination of the available quality dimensions."
    parts = [f"{k.replace('_', ' ')}: {v}" for k, v in labels.items()]
    parts += [f"{k.replace('_', ' ')}: {v:g}" for k, v in scores.items() if v is not None]
    return "FacultyLens result — " + "; ".join(parts) + "." if parts else "FacultyLens produced this result from the configured rules and scores."
