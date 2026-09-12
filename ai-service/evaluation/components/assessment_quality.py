"""Assessment quality engine and recommendation engine vs manual reviewer judgements."""

from __future__ import annotations

import re
from typing import Any, Dict, List, Optional

from app.schemas.assessment_analysis import UnifiedAssessmentAnalysisRequest
from app.schemas.quality import QualityAnalysisRequest
from app.services.assessment_analysis_service import AssessmentAnalysisService
from app.services.assessment_quality_engine import AssessmentQualityEngine
from evaluation import metrics as M
from evaluation.common import ComponentResult, finalize, load_dataset, timed, truncate
from evaluation.components import Context
from evaluation.human_review import summarize_reviews

DIM_TO_CATEGORY = {"topic_coverage": "topic_coverage", "learning_outcome_coverage": "learning_outcome", "difficulty_balance": "difficulty", "cognitive_diversity": "cognitive_level", "question_diversity": "question_diversity", "marks_distribution": "marks_distribution"}
RATING_ORDER = ["REQUIRES_ATTENTION", "NEEDS_REVIEW", "FAIR", "GOOD", "EXCELLENT"]
_ACTION = re.compile(r"\b(add|include|introduce|increase|reduce|remove|replace|revise|rewrite|rebalance|redistribute|review|consider|adjust|align|diversify|ensure|cover|split|shift|convert|redesign|balance|allocate|re-?allocate|incorporate|expand|limit)\w*\b", re.IGNORECASE)
_INCREASE = re.compile(r"\b(add|more|increase|introduce|include|expand)\w*\b", re.IGNORECASE)
_DECREASE = re.compile(r"\b(reduce|fewer|remove|decrease|less|limit)\w*\b", re.IGNORECASE)
_OBJECTS = ["easy", "medium", "hard", "remember", "understand", "apply", "analyze", "evaluate", "create", "mcq", "descriptive", "short answer", "problem solving", "true/false", "conceptual", "analytical"]


def _questions(ctx: Context, case: Dict[str, Any]) -> List[Dict[str, Any]]:
    out = []
    for i, qid in enumerate(case["question_ids"], start=1):
        q = ctx.catalogue.question(qid)
        marks = (case.get("marks_override") or {}).get(qid, q["marks"])
        out.append({"id": qid, "number": i, "text": q["question"], "marks": marks, "question_type": q["expected_type"], "difficulty": q["expected_difficulty"], "cognitive_level": q["expected_cognitive_level"],
                    "topics": [q["expected_topic"]], "learning_outcome_code": q.get("learning_outcome")})
    return out


def evaluate(ctx: Context) -> List[ComponentResult]:
    quality = ComponentResult(component="ASSESSMENT_QUALITY", split="ALL(cases)")
    recs = ComponentResult(component="RECOMMENDATIONS", split="ALL(cases)")
    data = load_dataset("assessment_cases")
    pipeline = AssessmentAnalysisService(hf_service=ctx.hf)
    engine = AssessmentQualityEngine()
    scores: Dict[str, Dict[str, Any]] = {}
    flag_hits, flag_total = 0, 0
    per_dim: Dict[str, Dict[str, int]] = {d: {"tp": 0, "fp": 0, "fn": 0, "tn": 0} for d in DIM_TO_CATEGORY}
    flagged_scores, ok_scores = [], []
    rating_hits = 0
    engine_rating_hits = 0
    latencies: List[float] = []
    cat_tp = cat_fp = cat_fn = 0
    case_counts: List[tuple] = []
    structural = {"total": 0, "with_evidence": 0, "actionable": 0, "readable": 0, "with_problem": 0}
    contradictions = 0

    for case in data["cases"]:
        course = ctx.catalogue.courses[case["course"]]
        qs = _questions(ctx, case)
        los = [{"id": code, "code": code, "description": desc} for code, desc in course["learning_outcomes"].items()]
        request = UnifiedAssessmentAnalysisRequest(course_id=case["course"], course_name=course["name"], assessment={"id": case["id"], "title": case["title"], "total_marks": case["declared_total_marks"], "course_code": case["course"]},
                                                   questions=qs, learning_outcomes=los, course_topics=course["topics"], previous_questions=case.get("previous_questions", []))
        try:
            out, ms = timed(pipeline.analyze_assessment, request)
        except Exception as exc:
            quality.failure_count += 1
            quality.errors.append({"id": case["id"], "error_type": "MODEL_FAILURE", "note": type(exc).__name__})
            continue
        latencies.append(ms)
        # engine-only run with the reviewer's faculty metadata (isolates the deterministic engine from classifier errors)
        engine_out = engine.analyze(QualityAnalysisRequest(assessment={"id": case["id"], "title": case["title"], "total_marks": case["declared_total_marks"]}, questions=qs,
                                                           topics=[{"name": t} for t in course["topics"]], learning_outcomes=[{"code": lo["code"], "description": lo["description"]} for lo in los]))
        qa = out.get("quality_analysis") or {}
        rec_items = (out.get("recommendations") or {}).get("recommendations", [])
        categories = {r.get("category") for r in rec_items}
        scores[case["id"]] = {"pipeline_score": qa.get("overall_quality_score"), "pipeline_rating": qa.get("rating"), "engine_score": engine_out.overall_quality_score, "engine_rating": engine_out.rating}

        # per-dimension flag agreement (system flag = a recommendation exists in the mapped category)
        components = qa.get("components") or {}
        for dim, cat in DIM_TO_CATEGORY.items():
            expected_flag = case["expected_flags"][dim] == "FLAG"
            system_flag = cat in categories
            flag_total += 1
            flag_hits += expected_flag == system_flag
            key = "tp" if expected_flag and system_flag else ("fn" if expected_flag else ("fp" if system_flag else "tn"))
            per_dim[dim][key] += 1
            score = components.get(dim)
            if score is not None:
                (flagged_scores if expected_flag else ok_scores).append(float(score))
            if expected_flag != system_flag:
                quality.errors.append({"id": case["id"], "case": case["title"], "dimension": dim, "expected": "FLAG" if expected_flag else "OK", "actual": "FLAG" if system_flag else "OK", "component_score": score,
                                       "error_type": "WRONG_CLASSIFICATION", "reviewer_note": case.get("review_note")})
        rating_hits += qa.get("rating") in case["rating_band"]
        engine_rating_hits += engine_out.rating in case["rating_band"]
        if qa.get("rating") not in case["rating_band"]:
            quality.errors.append({"id": case["id"], "case": case["title"], "expected_rating_band": case["rating_band"], "actual_rating": qa.get("rating"), "score": qa.get("overall_quality_score"), "error_type": "WRONG_CLASSIFICATION", "note": "overall rating outside reviewer band"})

        # recommendation categories vs reviewer expectation
        expected_cats = set(case["expected_problem_categories"])
        cat_tp += len(expected_cats & categories)
        cat_fp += len(categories - expected_cats)
        cat_fn += len(expected_cats - categories)
        case_counts.append((len(expected_cats & categories), len(categories - expected_cats), len(expected_cats - categories)))
        for missing in sorted(expected_cats - categories):
            recs.errors.append({"id": case["id"], "case": case["title"], "expected_category": missing, "actual_categories": sorted(c for c in categories if c), "error_type": "WRONG_CLASSIFICATION", "note": "reviewer expected a recommendation in this category"})
        for extra in sorted(c for c in categories - expected_cats if c):
            texts = [truncate(r.get("recommendation", ""), 160) for r in rec_items if r.get("category") == extra][:2]
            recs.errors.append({"id": case["id"], "case": case["title"], "unexpected_category": extra, "examples": texts, "error_type": "FALSE_SIMILARITY" if extra == "semantic_similarity" else "WRONG_CLASSIFICATION", "note": "recommendation raised where the reviewer saw no problem"})
        # structural quality
        for r in rec_items:
            structural["total"] += 1
            structural["with_evidence"] += bool(r.get("evidence"))
            structural["with_problem"] += bool(r.get("problem"))
            structural["actionable"] += bool(_ACTION.search(r.get("recommendation", "")))
            structural["readable"] += 20 <= len(r.get("recommendation", "")) <= 600
        contradictions += _contradictions(rec_items)
        ctx.record("ASSESSMENT_QUALITY", {"id": case["id"], **scores[case["id"]], "categories": sorted(c for c in categories if c), "expected_categories": sorted(expected_cats), "recommendations": [truncate(r.get("recommendation", ""), 200) for r in rec_items]})

    # pairwise ordering
    pair_hits = pair_total = 0
    for pair in data.get("pairwise_expectations", []):
        a, b = scores.get(pair["better"]), scores.get(pair["worse"])
        if a and b and a["pipeline_score"] is not None and b["pipeline_score"] is not None:
            pair_total += 1
            pair_hits += a["pipeline_score"] > b["pipeline_score"]
            if a["pipeline_score"] <= b["pipeline_score"]:
                quality.errors.append({"id": f"{pair['better']}>{pair['worse']}", "expected": "better scores higher", "actual": f"{a['pipeline_score']} vs {b['pipeline_score']}", "error_type": "WRONG_CLASSIFICATION"})

    n_cases = len(scores)
    quality.sample_size = n_cases
    quality.latency = M.latency_summary(latencies)
    quality.metrics = {
        "flag_agreement": round(flag_hits / flag_total, 4) if flag_total else None, "flag_agreement_ci95": M.wilson_interval(flag_hits, flag_total), "flag_decisions": flag_total,
        "flag_kappa": _kappa(per_dim), "rating_band_agreement_pipeline": round(rating_hits / n_cases, 4) if n_cases else None, "rating_band_agreement_engine_only": round(engine_rating_hits / n_cases, 4) if n_cases else None,
        "pairwise_ordering_agreement": round(pair_hits / pair_total, 4) if pair_total else None, "pairwise_comparisons": pair_total,
        "mean_component_score_reviewer_flagged": round(sum(flagged_scores) / len(flagged_scores), 2) if flagged_scores else None, "mean_component_score_reviewer_ok": round(sum(ok_scores) / len(ok_scores), 2) if ok_scores else None,
    }
    quality.structured = {"per_dimension": {d: {**v, "agreement": round((v["tp"] + v["tn"]) / max(1, sum(v.values())), 4)} for d, v in per_dim.items()}, "case_scores": scores}
    quality.notes += [
        "8 reviewer-labelled assessments composed from the question set. A dimension counts as system-FLAG when the unified pipeline raised a recommendation in the mapped category; rating bands are the reviewer's acceptable set.",
        "Pipeline results include AI re-detection of type/difficulty/Bloom; engine-only results use the reviewer's faculty metadata. The gap between the two rating agreements isolates classifier error from engine logic.",
    ]
    finalize(quality, ctx.gates, quality.metrics["flag_agreement_ci95"])

    prec = cat_tp / (cat_tp + cat_fp) if (cat_tp + cat_fp) else None
    rec = cat_tp / (cat_tp + cat_fn) if (cat_tp + cat_fn) else None
    f1 = (2 * prec * rec / (prec + rec)) if prec and rec else (0.0 if (cat_tp + cat_fp + cat_fn) else None)
    t = structural["total"]
    recs.sample_size = n_cases
    recs.latency = quality.latency
    recs.metrics = {
        "category_precision": _r(prec), "category_recall": _r(rec), "category_f1": _r(f1), "expected_categories": cat_tp + cat_fn, "raised_categories": cat_tp + cat_fp,
        "recommendations_generated": t, "evidence_rate": _ratio(structural["with_evidence"], t), "problem_linked_rate": _ratio(structural["with_problem"], t), "actionable_rate": _ratio(structural["actionable"], t),
        "readable_length_rate": _ratio(structural["readable"], t), "contradiction_count": contradictions, "non_contradictory_rate": _ratio(n_cases - min(n_cases, contradictions), n_cases),
    }
    recs.human_review = summarize_reviews("RECOMMENDATIONS")
    recs.notes += [
        "Category precision/recall compare raised recommendation categories with the reviewer's expected problem categories per assessment (micro-averaged over 8 cases).",
        "evidence/actionable/readable/contradiction are automated structural checks. Relevance, clarity and academic appropriateness on the 1-5 scale need faculty review forms — see human_review.",
    ]
    finalize(recs, ctx.gates, M.bootstrap_ci(case_counts, _f1_from_counts, seed=ctx.seed))
    return [quality, recs]


def _f1_from_counts(items: List[tuple]) -> Optional[float]:
    tp = sum(i[0] for i in items)
    fp = sum(i[1] for i in items)
    fn = sum(i[2] for i in items)
    if tp + fp + fn == 0:
        return None
    p = tp / (tp + fp) if (tp + fp) else 0.0
    r = tp / (tp + fn) if (tp + fn) else 0.0
    return 2 * p * r / (p + r) if (p + r) else 0.0


def _contradictions(items: List[Dict[str, Any]]) -> int:
    count = 0
    by_cat: Dict[str, List[str]] = {}
    for r in items:
        by_cat.setdefault(r.get("category", ""), []).append(r.get("recommendation", "").lower())
    for texts in by_cat.values():
        for obj in _OBJECTS:
            inc = any(obj in t and _INCREASE.search(t) and not _DECREASE.search(t) for t in texts)
            dec = any(obj in t and _DECREASE.search(t) and not _INCREASE.search(t) for t in texts)
            if inc and dec:
                count += 1
                break
    return count


def _kappa(per_dim: Dict[str, Dict[str, int]]) -> Optional[float]:
    a, b = [], []
    for v in per_dim.values():
        a += ["FLAG"] * (v["tp"] + v["fn"]) + ["OK"] * (v["fp"] + v["tn"])
        b += ["FLAG"] * v["tp"] + ["OK"] * v["fn"] + ["FLAG"] * v["fp"] + ["OK"] * v["tn"]
    return M.cohen_kappa(a, b)


def _ratio(a: int, b: int) -> Optional[float]:
    return round(a / b, 4) if b else None


def _r(v):
    return round(v, 4) if v is not None else None
