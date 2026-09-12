"""AI-assisted grading vs faculty marks: error metrics, agreement and bias analysis."""

from __future__ import annotations

from collections import defaultdict
from typing import Any, Dict, List, Tuple

from app.schemas.grading import GradeAnswerRequest
from app.services.grading_engine import GradingEngine
from evaluation import metrics as M
from evaluation.common import ComponentResult, finalize, load_dataset, timed, truncate
from evaluation.components import Context

LARGE_ERROR_FRACTION = 0.25  # |AI - faculty| >= 25% of the question total counts as GRADING_ERROR
TOLERANCES = (0.5, 1.0, 2.0)


def build_request(item: Dict[str, Any], answer: Dict[str, Any]) -> GradeAnswerRequest:
    return GradeAnswerRequest(
        student_answer={"text": answer["text"]},
        question={"text": item["question"], "total_marks": item["total_marks"], "question_type": item["question_type"], "difficulty_level": item.get("difficulty_level"), "cognitive_level": item.get("cognitive_level")},
        rubric={"total_marks": item["total_marks"], "criteria": [{"id": c["id"], "criterion": c["criterion"], "description": c["description"], "max_marks": c["max_marks"], "expected_indicators": c.get("expected_indicators", []), "sort_order": i + 1} for i, c in enumerate(item["rubric"])]},
    )


def evaluate(ctx: Context) -> List[ComponentResult]:
    res = ComponentResult(component="AI_GRADING", split="ALL(benchmark)")
    engine = GradingEngine(hf_service=ctx.hf, text_generation_service=ctx.text_generation)
    data = load_dataset("grading")
    pairs: List[Tuple[float, float]] = []
    normalized: List[float] = []
    totals: List[float] = []
    latencies: List[float] = []
    groups: Dict[str, Dict[str, List[float]]] = defaultdict(lambda: defaultdict(list))
    methods: Dict[str, int] = {}
    for item in data["items"]:
        rubric_total = round(sum(c["max_marks"] for c in item["rubric"]), 2)
        if abs(rubric_total - item["total_marks"]) > 0.005:
            res.notes.append(f"{item['id']}: rubric marks {rubric_total} != total {item['total_marks']} — item skipped")
            continue
        for ans in item["answers"]:
            try:
                out, ms = timed(engine.grade, build_request(item, ans))
            except Exception as exc:
                res.failure_count += 1
                res.errors.append({"id": ans["id"], "error_type": "MODEL_FAILURE", "note": type(exc).__name__})
                continue
            latencies.append(ms)
            ai, faculty, total = float(out["suggested_marks"]), float(ans["faculty_marks"]), float(item["total_marks"])
            err = ai - faculty
            pairs.append((ai, faculty))
            normalized.append(abs(err) / total)
            totals.append(total)
            methods[out["metadata"]["generation_method"]] = methods.get(out["metadata"]["generation_method"], 0) + 1
            words = len(ans["text"].split())
            quality_tier = "STRONG" if faculty / total >= 0.8 else ("PARTIAL" if faculty / total >= 0.4 else "WEAK")
            dims = {"question_type": item["question_type"], "difficulty": item.get("difficulty_level"), "cognitive_level": item.get("cognitive_level"), "course": item["course"],
                    "marks_band": "<=3" if total <= 3 else ("4-5" if total <= 5 else ">5"), "answer_length": "short(<40w)" if words < 40 else ("medium(40-80w)" if words <= 80 else "long(>80w)"), "faculty_quality_tier": quality_tier}
            for d, v in dims.items():
                groups[d][str(v)].append(err)
            large = abs(err) >= LARGE_ERROR_FRACTION * total
            if large:
                res.errors.append({"id": ans["id"], "question_id": item["question_id"], "question": truncate(item["question"], 100), "answer": truncate(ans["text"], 160), "faculty_marks": faculty, "ai_marks": ai, "total_marks": total,
                                   "signed_error": round(err, 2), "faculty_rationale": ans.get("rationale"), "criterion_results": [{"criterion": c["criterion"], "ai": c["suggested_marks"], "max": c["maximum_marks"], "coverage": c["coverage_level"]} for c in out["criterion_results"]],
                                   "error_type": "GRADING_ERROR", "direction": "OVER" if err > 0 else "UNDER"})
            ctx.record("AI_GRADING", {"id": ans["id"], "faculty_marks": faculty, "ai_marks": ai, "total": total, "signed_error": round(err, 4), "method": out["metadata"]["generation_method"], **dims})

    stats = M.error_stats(pairs)
    res.sample_size = stats["n"]
    res.latency = M.latency_summary(latencies)
    res.metrics = {
        **{k: v for k, v in stats.items() if k != "n"},
        "mae_normalized": round(sum(normalized) / len(normalized), 4) if normalized else None,
        "mae_normalized_ci95": M.bootstrap_ci(normalized, lambda v: sum(v) / len(v), seed=ctx.seed),
        "mae_ci95": M.bootstrap_ci(pairs, lambda items: M.error_stats(items)["mae"], seed=ctx.seed),
        "exact_agreement_rate": M.within_tolerance_rate(pairs, 0.004),
        **{f"within_{str(t).replace('.', '_')}_marks": M.within_tolerance_rate(pairs, t) for t in TOLERANCES},
        "within_10pct_of_total": round(sum(1 for v in normalized if v <= 0.10 + 1e-9) / len(normalized), 4) if normalized else None,
        "within_20pct_of_total": round(sum(1 for v in normalized if v <= 0.20 + 1e-9) / len(normalized), 4) if normalized else None,
        "large_error_rate": round(sum(1 for e in res.errors if e.get("error_type") == "GRADING_ERROR") / stats["n"], 4) if stats["n"] else None,
        "ai_mean_marks_fraction": round(sum(a / t for (a, _), t in zip(pairs, totals)) / len(pairs), 4) if pairs else None,
        "faculty_mean_marks_fraction": round(sum(f / t for (_, f), t in zip(pairs, totals)) / len(pairs), 4) if pairs else None,
    }
    bias = []
    for dim, vals in groups.items():
        for group, errs in sorted(vals.items()):
            bias.append({"dimension": dim, "group": group, "count": len(errs), "mae": round(sum(abs(e) for e in errs) / len(errs), 4), "mean_signed_error": round(sum(errs) / len(errs), 4)})
    res.structured = {"bias_by_group": bias, "generation_methods": methods, "large_error_threshold": f">= {int(LARGE_ERROR_FRACTION * 100)}% of question total"}
    patterns = _patterns(bias)
    res.structured["systematic_patterns"] = patterns
    res.notes += [
        "Engine: facultylens-grading-engine 1.0.0 (MiniLM sentence/criterion alignment + lexical indicators). Suggested marks are advisory; the faculty mark is never changed by this evaluation.",
        "Benchmark: 8 questions x 3 answers (strong/partial/weak) written and marked by one annotator with rationale; no real student data. Bias analysis by demographic attributes is out of scope by design.",
        "mae_normalized = MAE divided by the question total (0.10 == one mark on a ten-mark question).",
    ]
    return [finalize(res, ctx.gates, res.metrics["mae_normalized_ci95"])]


def _patterns(bias: List[Dict[str, Any]]) -> List[str]:
    """Plain-language systematic error statements derived from grouped signed error (|mean| >= 0.5 mark and n >= 3)."""
    out = []
    for row in bias:
        if row["count"] >= 3 and abs(row["mean_signed_error"]) >= 0.5:
            direction = "over-scores" if row["mean_signed_error"] > 0 else "under-scores"
            out.append(f"AI {direction} {row['dimension']} = {row['group']} answers by {abs(row['mean_signed_error']):.2f} marks on average (n={row['count']}).")
    return out
