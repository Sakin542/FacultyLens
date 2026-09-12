"""Constrained question generation: independent constraint checks + document-grounding (hallucination) test."""

from __future__ import annotations

import re
from typing import Any, Dict, List, Optional

from app.schemas.question_generation import GenerateQuestionsRequest
from app.services.question_generator import QuestionGenerator
from app.services.retrieval import cosine_similarity
from evaluation import metrics as M
from evaluation.common import ComponentResult, finalize, load_dataset, timed, truncate
from evaluation.components import Context
from evaluation.human_review import summarize_reviews

_LEAK = re.compile(r"(ignore (all )?previous instructions|system prompt|api key|reveal your)", re.IGNORECASE)
_WORD = re.compile(r"[A-Za-z][A-Za-z\-]{3,}")
# Words the template engine may legitimately add without document support (scaffolding, not facts).
_SCAFFOLD = {
    "explain", "describe", "discuss", "analyze", "analyse", "compare", "evaluate", "design", "calculate", "identify", "justify", "state", "define", "list", "briefly", "outline", "write", "solve", "compute", "propose",
    "question", "questions", "answer", "marks", "mark", "example", "examples", "following", "given", "using", "based", "with", "that", "this", "which", "what", "when", "where", "true", "false", "statement", "whether",
    "course", "topic", "lecture", "chapter", "unit", "students", "student", "learning", "outcome", "concept", "concepts", "approach", "approaches", "method", "methods", "case", "study", "scenario", "system", "systems",
    "role", "importance", "advantages", "disadvantages", "difference", "differences", "between", "relationship", "impact", "consider", "considering", "suitable", "appropriate", "correct", "option", "options", "choose", "select",
    "purpose", "significance", "situation", "concrete", "works", "typical", "illustrate", "support", "argument", "evidence", "critically", "assess", "justification", "strengths", "weaknesses", "limitations", "benefits",
    "apply", "applied", "demonstrate", "determine", "derive", "implement", "construct", "develop", "formulate", "critique", "recommend", "prove", "trace", "sketch", "draw", "argue", "examine", "investigate", "interpret",
    "summarize", "summarise", "classify", "predict", "estimate", "measure", "verify", "validate", "test", "check", "comment", "distinguish", "differentiate", "contrast", "relate", "generalize", "extend", "modify", "optimize",
    "optimise", "handle", "manage", "affects", "affect", "involved", "detailed", "complex", "realistic", "reasoning", "trade", "offs", "trade-offs", "note", "brief", "words", "short", "notes",
    "real", "world", "applications", "application", "practical", "academic", "decisions", "decision", "performance", "correctness", "requirements", "requirement", "terms", "context", "aspects", "aspect",
    "step", "steps", "show", "work", "working", "value", "values", "result", "results", "problem", "problems", "why", "how", "does", "would", "should", "could", "from", "into", "than", "then", "your", "their", "about",
    "also", "each", "both", "such", "more", "most", "less", "very", "well", "used", "uses", "user", "users", "process", "processes", "data", "model", "models", "include", "including", "give", "provide", "name",
}


def build_request(ctx: Context, spec: Dict[str, Any], rag_chunks: Dict[int, Dict[str, Any]]) -> GenerateQuestionsRequest:
    course = ctx.catalogue.courses[spec["course"]]
    payload: Dict[str, Any] = {
        "course_context": {"course_code": spec["course"], "course_name": course["name"]},
        "topic": spec.get("topic"), "question_type": spec.get("question_type", "DESCRIPTIVE"), "difficulty_level": spec.get("difficulty_level"), "cognitive_level": spec.get("cognitive_level"),
        "marks": spec.get("marks", 10), "number_of_questions": spec.get("number_of_questions", 1), "language": spec.get("language", "English"),
    }
    if spec.get("learning_outcome"):
        payload["learning_outcome"] = {"code": spec["learning_outcome"], "description": course["learning_outcomes"][spec["learning_outcome"]], "cognitive_level": spec.get("cognitive_level")}
    if spec.get("document_chunk_ids"):
        payload["document_context"] = [{"chunk_id": cid, "document_id": rag_chunks[cid]["document_id"], "document_name": rag_chunks[cid]["document_name"], "content": rag_chunks[cid]["content"], "similarity_score": 0.8} for cid in spec["document_chunk_ids"]]
    if spec.get("existing_question_ids"):
        payload["existing_question_context"] = [{"id": i, "text": ctx.catalogue.question(qid)["question"], "source": "previous", "label": qid} for i, qid in enumerate(spec["existing_question_ids"], start=1)]
    if spec.get("blueprint"):
        payload["blueprint"] = spec["blueprint"]
    return GenerateQuestionsRequest(**payload)


def expected_slots(spec: Dict[str, Any]) -> List[Dict[str, Any]]:
    if spec.get("blueprint"):
        slots = []
        for slot in spec["blueprint"]:
            for _ in range(slot.get("count", 1)):
                slots.append({"question_type": slot.get("question_type") or spec.get("question_type"), "difficulty_level": slot.get("difficulty_level") or spec.get("difficulty_level"),
                              "cognitive_level": slot.get("cognitive_level") or spec.get("cognitive_level"), "marks": slot.get("marks") or spec.get("marks")})
        return slots
    return [{"question_type": spec.get("question_type"), "difficulty_level": spec.get("difficulty_level"), "cognitive_level": spec.get("cognitive_level"), "marks": spec.get("marks")} for _ in range(spec.get("number_of_questions", 1))]


def check_question(ctx: Context, spec: Dict[str, Any], slot: Dict[str, Any], gq: Dict[str, Any], existing_vecs: List[List[float]]) -> Dict[str, Optional[bool]]:
    text = gq["question_text"]
    val = gq.get("validation", {}) or {}
    checks: Dict[str, Optional[bool]] = {
        "type": gq["question_type"] == slot["question_type"],
        "marks": abs(float(gq["marks"]) - float(slot["marks"])) < 0.005,
        "difficulty": (gq.get("difficulty_level") == slot["difficulty_level"]) if slot.get("difficulty_level") else None,
        "bloom": (gq.get("cognitive_level") == slot["cognitive_level"]) if slot.get("cognitive_level") else None,
        "topic": _topic_present(spec.get("topic"), text, gq.get("topic")) if spec.get("topic") else None,
        "lo": (val.get("co_alignment_status") in ("STRONG", "WEAK")) if spec.get("learning_outcome") else None,
        "language": _is_english(text),
        "format": _format_ok(gq),
        "non_empty": len(text.split()) >= 5,
        "no_leak": not _LEAK.search(text),
    }
    if existing_vecs:
        vec = ctx.hf.generate_embedding(text)
        max_sim = max(cosine_similarity(vec, e) for e in existing_vecs)
        checks["similarity"] = max_sim < ctx.settings.similarity_duplicate_threshold
        checks["_max_similarity"] = round(max_sim, 4)  # type: ignore[assignment]
    else:
        checks["similarity"] = None
    return checks


def hallucination_check(text: str, evidence: str, allowed: str, domain_vocab: set) -> Dict[str, Any]:
    """Domain terms in the draft that appear neither in the grounding documents nor in the request context.

    Domain terms = words from the course catalogue / corpus vocabulary or capitalised tokens & acronyms; generic academic
    scaffolding ("explain", "briefly", "scenario") is ignored. Matching uses a 5-character prefix as a cheap stemmer.
    """
    haystack = _tokens(f"{evidence} {allowed}")
    prefixes = {t[:5] for t in haystack}
    candidates = []
    for raw in _WORD.findall(text):
        w = raw.lower()
        if w in _SCAFFOLD:
            continue
        is_domain = w in domain_vocab or raw[:1].isupper() or raw.isupper()
        if is_domain:
            candidates.append(w)
    content = sorted(set(candidates))
    unsupported = [w for w in content if w not in haystack and w[:5] not in prefixes]
    return {"unsupported_terms": unsupported, "content_terms": len(content), "unsupported_rate": round(len(unsupported) / len(content), 4) if content else 0.0, "flag": len(unsupported) >= 1}


def _tokens(text: str) -> set:
    return {w.lower() for w in _WORD.findall(text)}


def domain_vocabulary(ctx: Context, rag_chunks: Dict[int, Dict[str, Any]]) -> set:
    text = " ".join(" ".join(c["topics"]) + " " + " ".join(c["learning_outcomes"].values()) + " " + c["name"] for c in ctx.catalogue.courses.values())
    text += " " + " ".join(c["content"] for c in rag_chunks.values())
    return {w for w in _tokens(text) if w not in _SCAFFOLD}


def _topic_present(topic: Optional[str], text: str, declared: Optional[str]) -> bool:
    """Topic word (or its 5-char stem, e.g. 'normal' for 'Normalization') appears in the draft, or the draft declares the topic."""
    if not topic:
        return True
    text_tokens = _tokens(text)
    prefixes = {t[:5] for t in text_tokens}
    words = [w for w in re.findall(r"[a-z]{4,}", topic.lower()) if w not in {"and", "with"}]
    return any(w in text_tokens or w[:5] in prefixes for w in words) or (declared or "").strip().lower() == topic.strip().lower()


def _is_english(text: str) -> bool:
    letters = [c for c in text if c.isalpha()]
    return bool(letters) and sum(1 for c in letters if c.isascii()) / len(letters) >= 0.97


def _format_ok(gq: Dict[str, Any]) -> bool:
    if gq["question_type"] == "MCQ":
        return bool(gq.get("options")) and len(gq["options"]) >= 2 and bool(gq.get("correct_option"))
    if gq["question_type"] == "TRUE_FALSE":
        return "true or false" in gq["question_text"].lower() or "true/false" in gq["question_text"].lower()
    return True


def evaluate(ctx: Context) -> List[ComponentResult]:
    res = ComponentResult(component="QUESTION_GENERATION", split="ALL(requests)")
    generator = QuestionGenerator(generation_service=ctx.generation, hf_service=ctx.hf)
    data = load_dataset("generation")
    rag = load_dataset("rag")
    rag_chunks = {c["chunk_id"]: {**c, "document_id": d["document_id"], "document_name": d["document_name"]} for d in rag["documents"] for c in d["chunks"]}
    vocab = domain_vocabulary(ctx, rag_chunks)
    latencies: List[float] = []
    per_constraint: Dict[str, List[bool]] = {}
    fully_ok = total_q = 0
    completeness: List[float] = []
    self_status: Dict[str, int] = {}
    methods: Dict[str, int] = {}
    grounded_total = grounded_flagged = 0
    unsupported_rates: List[float] = []
    detector_agreement: Dict[str, List[bool]] = {"difficulty": [], "bloom": [], "type": []}

    for spec in data["question_generation_requests"]:
        try:
            request = build_request(ctx, spec, rag_chunks)
            out, ms = timed(generator.generate, request)
        except Exception as exc:
            res.failure_count += 1
            res.errors.append({"id": spec["id"], "error_type": "MODEL_FAILURE", "note": type(exc).__name__})
            continue
        latencies.append(ms)
        methods[out["generation_method"]] = methods.get(out["generation_method"], 0) + 1
        slots = expected_slots(spec)
        completeness.append(len(out["questions"]) / len(slots) if slots else 1.0)
        existing_vecs = ctx.hf.generate_batch_embeddings([e.text for e in request.existing_question_context]) if request.existing_question_context else []
        evidence = " ".join(rag_chunks[c]["content"] for c in spec.get("document_chunk_ids", []))
        allowed = " ".join(filter(None, [spec.get("topic"), ctx.catalogue.courses[spec["course"]]["name"], spec["course"], ctx.catalogue.los(spec["course"]).get(spec.get("learning_outcome") or "", "")]))
        for i, gq in enumerate(out["questions"]):
            slot = slots[i] if i < len(slots) else slots[-1]
            checks = check_question(ctx, spec, slot, gq, existing_vecs)
            total_q += 1
            failed = [k for k, v in checks.items() if v is False and not k.startswith("_")]
            for k, v in checks.items():
                if v is not None and not k.startswith("_"):
                    per_constraint.setdefault(k, []).append(bool(v))
            fully_ok += not failed
            val = gq.get("validation", {}) or {}
            self_status[val.get("overall_status", "UNKNOWN")] = self_status.get(val.get("overall_status", "UNKNOWN"), 0) + 1
            if val.get("detected_difficulty") and slot.get("difficulty_level"):
                detector_agreement["difficulty"].append(val["detected_difficulty"] == slot["difficulty_level"])
            if val.get("detected_cognitive_level") and slot.get("cognitive_level"):
                detector_agreement["bloom"].append(val["detected_cognitive_level"] == slot["cognitive_level"])
            if val.get("detected_question_type"):
                detector_agreement["type"].append(val["detected_question_type"] == slot["question_type"])
            halluc = None
            if spec.get("grounded"):
                grounded_total += 1
                halluc = hallucination_check(gq["question_text"], evidence, allowed, vocab)
                unsupported_rates.append(halluc["unsupported_rate"])
                if halluc["flag"]:
                    grounded_flagged += 1
                    res.errors.append({"id": f"{spec['id']}#{i + 1}", "question": truncate(gq["question_text"], 200), "unsupported_terms": halluc["unsupported_terms"], "error_type": "HALLUCINATION",
                                       "note": "content terms absent from the grounding documents and request context (lexical proxy)"})
            if failed:
                res.errors.append({"id": f"{spec['id']}#{i + 1}", "question": truncate(gq["question_text"], 200), "requested": slot, "actual": {"type": gq["question_type"], "marks": gq["marks"], "difficulty": gq.get("difficulty_level"), "bloom": gq.get("cognitive_level")},
                                   "failed_constraints": failed, "max_similarity": checks.get("_max_similarity"), "self_validation": val.get("overall_status"), "error_type": "CONSTRAINT_VIOLATION"})
            ctx.record("QUESTION_GENERATION", {"id": f"{spec['id']}#{i + 1}", "question": gq["question_text"], "type": gq["question_type"], "marks": gq["marks"], "difficulty": gq.get("difficulty_level"), "bloom": gq.get("cognitive_level"),
                                               "checks": checks, "self_validation": val.get("overall_status"), "hallucination": halluc, "method": out["generation_method"]})

    res.sample_size = total_q
    res.latency = M.latency_summary(latencies)
    all_checks = [v for vals in per_constraint.values() for v in vals]
    res.metrics = {
        "constraint_satisfaction_rate": round(sum(all_checks) / len(all_checks), 4) if all_checks else None,
        "constraint_satisfaction_ci95": M.wilson_interval(sum(all_checks), len(all_checks)),
        "fully_satisfied_question_rate": round(fully_ok / total_q, 4) if total_q else None,
        "generation_completeness": round(sum(completeness) / len(completeness), 4) if completeness else None,
        "per_constraint": {k: round(sum(v) / len(v), 4) for k, v in sorted(per_constraint.items())},
        "grounded_drafts": grounded_total, "hallucination_flag_rate": round(grounded_flagged / grounded_total, 4) if grounded_total else None,
        "mean_unsupported_term_rate": round(sum(unsupported_rates) / len(unsupported_rates), 4) if unsupported_rates else None,
        "self_validator_agreement": {k: (round(sum(v) / len(v), 4) if v else None) for k, v in detector_agreement.items()},
        "requests": len(data["question_generation_requests"]),
    }
    res.structured = {"generation_methods": methods, "self_validation_status": self_status}
    res.human_review = summarize_reviews("QUESTION_GENERATION")
    res.notes += [
        f"Generator actually used: {'generative ' + str(ctx.generation.model_name) if getattr(ctx.generation, 'is_configured', False) else 'facultylens-constrained-question-template-engine 1.0.0 (no HF_GENERATION_MODEL configured)'}; prompt version {out['prompt_version'] if latencies else 'n/a'}.",
        "Constraint checks are computed independently of the generator's own validation; self_validator_agreement shows how often the service's internal classifier agrees with the requested slot (a self-consistency signal, not accuracy).",
        "Hallucination check is a lexical proxy applied to the question text: domain-vocabulary terms (course/corpus words, capitalised tokens, acronyms) that appear neither in the grounding chunks nor in the request context (topic, LO, course). Generic scaffolding is ignored. Drafts stay DRAFT until faculty approval; quality needs the human review form.",
    ]
    return [finalize(res, ctx.gates, res.metrics["constraint_satisfaction_ci95"])]
