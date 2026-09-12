"""Rubric generation: structural constraint satisfaction (marks sum, criteria validity) + human review hook."""

from __future__ import annotations

import re
from typing import Any, Dict, List

from app.schemas.rubric import GenerateRubricRequest
from app.services.rubric_generator import RubricGenerator
from evaluation import metrics as M
from evaluation.common import ComponentResult, finalize, load_dataset, timed, truncate
from evaluation.components import Context
from evaluation.human_review import summarize_reviews

_STOP = {"the", "and", "with", "that", "this", "for", "from", "into", "using", "explain", "describe", "discuss", "which", "what", "your", "each", "their", "between", "given"}


def build_request(ctx: Context, q: Dict[str, Any]) -> GenerateRubricRequest:
    lo = None
    if q.get("learning_outcome"):
        lo = {"code": q["learning_outcome"], "description": ctx.catalogue.los(q["course"])[q["learning_outcome"]]}
    return GenerateRubricRequest(question_id=None, question_text=q["question"], question_type=q["expected_type"], total_marks=q["marks"], difficulty_level=q["expected_difficulty"],
                                 cognitive_level=q["expected_cognitive_level"], learning_outcome=lo, course_context={"course_code": q["course"], "course_name": ctx.catalogue.courses[q["course"]]["name"]})


def check_rubric(rubric: Dict[str, Any], total: float, max_criteria: int) -> Dict[str, bool]:
    criteria = rubric.get("criteria", [])
    names = [str(c.get("criterion", "")).strip().lower() for c in criteria]
    return {
        "marks_sum_equals_total": abs(round(sum(float(c.get("max_marks", 0)) for c in criteria), 2) - round(total, 2)) <= 0.005,
        "criteria_count_in_range": 1 <= len(criteria) <= max_criteria,
        "all_marks_positive": all(float(c.get("max_marks", 0)) > 0 for c in criteria),
        "criteria_named": all(n for n in names),
        "criteria_unique": len(set(names)) == len(names),
        "descriptions_present": all(str(c.get("description", "")).strip() for c in criteria),
        "sort_order_sequential": [c.get("sort_order") for c in criteria] == list(range(1, len(criteria) + 1)),
    }


def question_alignment_proxy(question: str, rubric: Dict[str, Any]) -> float:
    """Share of question content words that appear somewhere in the rubric text (lexical proxy, not quality)."""
    words = {w for w in re.findall(r"[a-z][a-z\-]{3,}", question.lower()) if w not in _STOP}
    if not words:
        return 0.0
    text = " ".join(f"{c.get('criterion', '')} {c.get('description', '')} {' '.join(c.get('expected_indicators', []))}" for c in rubric.get("criteria", [])).lower()
    return round(sum(1 for w in words if w in text) / len(words), 4)


def evaluate(ctx: Context) -> List[ComponentResult]:
    res = ComponentResult(component="RUBRIC_GENERATION", split="ALL(rubric_question_ids)")
    generator = RubricGenerator(hf_service=ctx.hf, text_generation_service=ctx.text_generation)
    ids = load_dataset("generation")["rubric_question_ids"]
    latencies: List[float] = []
    check_totals: Dict[str, List[bool]] = {}
    passes = 0
    methods: Dict[str, int] = {}
    alignment_proxy: List[float] = []
    criteria_counts: List[int] = []
    for qid in ids:
        q = ctx.catalogue.question(qid)
        try:
            out, ms = timed(generator.generate, build_request(ctx, q))
        except Exception as exc:
            res.failure_count += 1
            res.errors.append({"id": qid, "error_type": "MODEL_FAILURE", "note": type(exc).__name__})
            continue
        latencies.append(ms)
        rubric = out["rubric"]
        checks = check_rubric(rubric, q["marks"], ctx.settings.rubric_max_criteria)
        for k, v in checks.items():
            check_totals.setdefault(k, []).append(v)
        ok = all(checks.values())
        passes += ok
        methods[str(out["generation_method"])] = methods.get(str(out["generation_method"]), 0) + 1
        alignment_proxy.append(question_alignment_proxy(q["question"], rubric))
        criteria_counts.append(len(rubric["criteria"]))
        if not ok:
            res.errors.append({"id": qid, "question": truncate(q["question"], 100), "total_marks": q["marks"], "failed_checks": [k for k, v in checks.items() if not v],
                               "criteria": [{"criterion": c["criterion"], "max_marks": c["max_marks"]} for c in rubric["criteria"]], "error_type": "WRONG_RUBRIC"})
        ctx.record("RUBRIC_GENERATION", {"id": qid, "checks": checks, "criteria_count": len(rubric["criteria"]), "method": str(out["generation_method"]), "alignment_proxy": alignment_proxy[-1],
                                         "criteria": [{"criterion": c["criterion"], "max_marks": c["max_marks"]} for c in rubric["criteria"]]})
    n = passes + sum(1 for e in res.errors if e.get("error_type") == "WRONG_RUBRIC")
    res.sample_size = n
    res.latency = M.latency_summary(latencies)
    res.metrics = {
        "constraint_satisfaction_rate": round(passes / n, 4) if n else None, "constraint_satisfaction_ci95": M.wilson_interval(passes, n),
        "marks_validity_rate": _rate(check_totals.get("marks_sum_equals_total", [])),
        "per_check": {k: _rate(v) for k, v in check_totals.items()},
        "mean_criteria_count": round(sum(criteria_counts) / len(criteria_counts), 2) if criteria_counts else None,
        "question_alignment_lexical_proxy": round(sum(alignment_proxy) / len(alignment_proxy), 4) if alignment_proxy else None,
    }
    res.structured = {"generation_methods": methods, "criteria_count_distribution": {str(k): criteria_counts.count(k) for k in sorted(set(criteria_counts))}}
    res.human_review = summarize_reviews("RUBRIC_GENERATION")
    res.notes += [
        "Structural constraints are verified independently of the generator's own validator. Rubric *quality* (relevance, clarity, specificity, academic appropriateness) requires faculty review — see human_review.",
        f"Generator actually used: {'seq2seq ' + str(ctx.text_generation.model_name) if getattr(ctx.text_generation, 'is_configured', False) else 'facultylens-rubric-template-engine 1.0.0 (RUBRIC_GENERATION_ENABLED=false)'}.",
        "question_alignment_lexical_proxy is a weak lexical indicator, not a quality judgement.",
    ]
    return [finalize(res, ctx.gates, res.metrics["constraint_satisfaction_ci95"])]


def _rate(values: List[bool]):
    return round(sum(1 for v in values if v) / len(values), 4) if values else None
