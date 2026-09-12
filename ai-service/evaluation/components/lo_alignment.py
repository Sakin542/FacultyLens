"""LO alignment: pair-level band agreement and question-level top-K LO ranking.

Two variants are measured: *bare* question text and the production path, in which the question
text is enriched with AI-detected topics before matching (as the unified STEP 15 pipeline does).
"""

from __future__ import annotations

from collections import Counter, defaultdict
from typing import Any, Dict, List, Optional, Tuple

from app.schemas.alignment import LearningOutcomeItem, QuestionItem, ThresholdsConfig
from app.services.lo_matcher import LearningOutcomeMatcher
from app.services.threshold_bands import classify_alignment
from app.services.topic_detector import TopicDetector
from evaluation import metrics as M
from evaluation.common import ComponentResult, finalize, load_dataset, timed, truncate
from evaluation.components import Context

BANDS = ["STRONG", "WEAK", "NOT_ALIGNED"]
STRONG, WEAK = 0.70, 0.50  # STEP 11 production thresholds (read-only here)


def _detected_topics(ctx: Context, question_ids: List[str]) -> Dict[str, List[str]]:
    detector = TopicDetector(hf_service=ctx.hf)
    out: Dict[str, List[str]] = {}
    for qid in question_ids:
        q = ctx.catalogue.question(qid)
        out[qid] = [t["name"] for t in detector.detect_topics(q["question"], course_topics=ctx.catalogue.topics(q["course"]))]
    return out


def _pairs(ctx: Context, matcher, pairs, thresholds, topics_map: Optional[Dict[str, List[str]]], res: ComponentResult, record: bool) -> Dict[str, Any]:
    cat = ctx.catalogue
    outcomes: List[Tuple[str, str]] = []
    scores_by_band: Dict[str, List[float]] = defaultdict(list)
    by_conf: Dict[str, List[bool]] = defaultdict(list)
    latencies: List[float] = []
    adjacent = severe = 0
    for p in pairs:
        q = cat.question(p["question_id"])
        _course, code, desc = cat.lo_text(p["lo"])
        item = QuestionItem(id=q["id"], text=q["question"], topics=(topics_map or {}).get(q["id"], []))
        try:
            out, ms = timed(matcher.match_questions_to_los, [item], [LearningOutcomeItem(id=code, code=code, description=desc)], thresholds)
        except Exception as exc:
            res.failure_count += 1
            res.errors.append({"id": p["id"], "error_type": "MODEL_FAILURE", "note": type(exc).__name__})
            continue
        latencies.append(ms)
        predicted, score, expected = out[0]["alignment_status"], out[0]["similarity_score"], p["expected_alignment"]
        outcomes.append((expected, predicted))
        scores_by_band[expected].append(score)
        correct = predicted == expected
        by_conf[p.get("label_confidence", "HIGH")].append(correct)
        if not correct:
            dist = abs(BANDS.index(expected) - BANDS.index(predicted))
            adjacent += dist == 1
            severe += dist == 2
            if record:
                res.errors.append({"id": p["id"], "question_id": q["id"], "input": truncate(q["question"]), "lo": p["lo"], "lo_text": truncate(desc, 100), "expected": expected, "actual": predicted, "similarity": score,
                                   "severity": "ADJACENT_BAND" if dist == 1 else "TWO_BANDS", "label_confidence": p.get("label_confidence"), "error_type": "WRONG_LO_ALIGNMENT"})
        if record:
            ctx.record("LO_ALIGNMENT_PAIRS", {"id": p["id"], "expected": expected, "predicted": predicted, "similarity": score, "correct": correct, "topics": (topics_map or {}).get(q["id"], [])})
    report = M.classification_report([e for e, _ in outcomes], [p for _, p in outcomes], BANDS)
    binary = M.classification_report(["ALIGNED" if e != "NOT_ALIGNED" else e for e, _ in outcomes], ["ALIGNED" if p != "NOT_ALIGNED" else p for _, p in outcomes])
    return {"outcomes": outcomes, "report": report, "binary": binary, "scores_by_band": scores_by_band, "by_conf": by_conf, "latencies": latencies, "adjacent": adjacent, "severe": severe}


def _ranking(ctx: Context, matcher, questions, thresholds, topics_map: Optional[Dict[str, List[str]]], res: ComponentResult, record: bool) -> Dict[str, Any]:
    cat = ctx.catalogue
    top1 = top2 = n = 0
    mrr: List[float] = []
    correct_scores, wrong_scores, latencies = [], [], []
    for q in questions:
        los = cat.los(q["course"])
        acceptable = {q["learning_outcome"], *q.get("acceptable_learning_outcomes", [])}
        item = QuestionItem(id=q["id"], text=q["question"], topics=(topics_map or {}).get(q["id"], []))
        try:
            out, ms = timed(matcher.match_questions_to_los, [item], [LearningOutcomeItem(id=c, code=c, description=d) for c, d in los.items()], thresholds)
        except Exception as exc:
            res.failure_count += 1
            res.errors.append({"id": q["id"], "error_type": "MODEL_FAILURE", "note": type(exc).__name__})
            continue
        latencies.append(ms)
        n += 1
        ranking = [out[0]["matched_learning_outcome"]["code"]] + [a["code"] for a in out[0]["alternative_matches"]]
        ranking += [c for c in los if c not in ranking]  # LOs below the weak threshold are not listed by the service; order among them is unknown
        hit1 = ranking[0] in acceptable
        top1 += hit1
        top2 += any(c in acceptable for c in ranking[:2])
        mrr.append(M.reciprocal_rank(ranking, acceptable) or 0.0)
        (correct_scores if hit1 else wrong_scores).append(out[0]["similarity_score"])
        if record and not hit1:
            res.errors.append({"id": q["id"], "course": q["course"], "input": truncate(q["question"]), "expected": q["learning_outcome"], "acceptable": sorted(acceptable), "actual": ranking[0], "actual_similarity": out[0]["similarity_score"],
                               "actual_band": out[0]["alignment_status"], "error_type": "WRONG_LO_ALIGNMENT", "severity": "TOP1_MISS", "label_confidence": q.get("label_confidence")})
        if record:
            ctx.record("LO_ALIGNMENT_RANKING", {"id": q["id"], "expected": q["learning_outcome"], "ranking": ranking[:3], "top1_correct": hit1, "similarity": out[0]["similarity_score"], "band": out[0]["alignment_status"]})
    return {"n": n, "top1": top1, "top2": top2, "mrr": mrr, "correct_scores": correct_scores, "wrong_scores": wrong_scores, "latencies": latencies}


def evaluate(ctx: Context) -> List[ComponentResult]:
    res = ComponentResult(component="LO_ALIGNMENT", split="ALL(pairs)+" + ctx.split + "(ranking)")
    matcher = LearningOutcomeMatcher(hf_service=ctx.hf)
    thresholds = ThresholdsConfig(strong=STRONG, weak=WEAK)
    pairs = load_dataset("lo_pairs")["pairs"]
    ranked_questions = [q for q in ctx.catalogue.select(ctx.split) if q.get("learning_outcome")]
    topics_map = _detected_topics(ctx, sorted({p["question_id"] for p in pairs} | {q["id"] for q in ranked_questions}))

    prod = _pairs(ctx, matcher, pairs, thresholds, topics_map, res, record=True)
    bare = _pairs(ctx, matcher, pairs, thresholds, None, res, record=False)
    rank_prod = _ranking(ctx, matcher, ranked_questions, thresholds, topics_map, res, record=True)
    rank_bare = _ranking(ctx, matcher, ranked_questions, thresholds, None, res, record=False)

    outcomes = prod["outcomes"]
    raw = [(e, s) for e, scores in prod["scores_by_band"].items() for s in scores]
    sweep = []
    for strong, weak in ((0.35, 0.20), (0.40, 0.25), (0.45, 0.30), (0.50, 0.35), (0.55, 0.40), (0.60, 0.45), (0.70, 0.50), (0.80, 0.60)):
        preds = [classify_alignment(s, strong, weak) for _, s in raw]
        rep = M.classification_report([e for e, _ in raw], preds, BANDS)
        sweep.append({"strong": strong, "weak": weak, "band_agreement": rep["accuracy"], "macro_f1": rep["macro_f1"], "production": strong == STRONG and weak == WEAK})

    res.sample_size = len(outcomes)
    res.latency = M.latency_summary(prod["latencies"] + rank_prod["latencies"])
    conf = prod["by_conf"]
    res.metrics = {
        "band_agreement": prod["report"]["accuracy"], "band_agreement_ci95": M.wilson_interval(sum(1 for e, p in outcomes if e == p), len(outcomes)),
        "macro_f1": prod["report"]["macro_f1"], "weighted_f1": prod["report"]["weighted_f1"], "kappa": M.cohen_kappa([e for e, _ in outcomes], [p for _, p in outcomes]),
        "aligned_vs_not_accuracy": prod["binary"]["accuracy"], "aligned_vs_not_f1": next((r["f1"] for r in prod["binary"]["per_class"] if r["label"] == "ALIGNED"), None),
        "adjacent_band_errors": prod["adjacent"], "two_band_errors": prod["severe"],
        "agreement_high_confidence_labels": _rate(conf.get("HIGH", [])), "agreement_medium_low_confidence_labels": _rate(conf.get("MEDIUM", []) + conf.get("LOW", [])),
        "top1_lo_accuracy": _ratio(rank_prod["top1"], rank_prod["n"]), "top2_lo_accuracy": _ratio(rank_prod["top2"], rank_prod["n"]), "top1_lo_accuracy_ci95": M.wilson_interval(rank_prod["top1"], rank_prod["n"]),
        "lo_mrr": _mean(rank_prod["mrr"]), "ranked_questions": rank_prod["n"],
        "band_agreement_bare_text": bare["report"]["accuracy"], "macro_f1_bare_text": bare["report"]["macro_f1"], "top1_lo_accuracy_bare_text": _ratio(rank_bare["top1"], rank_bare["n"]), "lo_mrr_bare_text": _mean(rank_bare["mrr"]),
    }
    res.structured = {
        "confusion_matrix": prod["report"]["confusion_matrix"], "per_class": prod["report"]["per_class"], "confusion_matrix_bare_text": bare["report"]["confusion_matrix"],
        "similarity_distribution_by_expected_band": {b: _dist(v) for b, v in prod["scores_by_band"].items()}, "similarity_distribution_by_expected_band_bare_text": {b: _dist(v) for b, v in bare["scores_by_band"].items()},
        "best_similarity_when_top1_correct": _dist(rank_prod["correct_scores"]), "best_similarity_when_top1_wrong": _dist(rank_prod["wrong_scores"]),
        "threshold_sweep": sweep, "production_thresholds": {"strong": STRONG, "weak": WEAK},
        "error_transitions": dict(Counter(f"{e}->{p}" for e, p in outcomes if e != p)),
    }
    res.notes += [
        "Headline numbers use the production path (question text enriched with AI-detected course topics, as in the unified pipeline); *_bare_text metrics use the raw question only.",
        "Pairs: all 86 labelled (question, LO) pairs; ranking: questions of the selected split with a labelled LO, against every LO of their course.",
        "Single annotator; WEAK is the least reliable label. The threshold sweep is informational — production thresholds were NOT changed by this evaluation.",
    ]
    ci = M.bootstrap_ci(outcomes, lambda items: sum(1 for e, p in items if e == p) / len(items), seed=ctx.seed)
    return [finalize(res, ctx.gates, ci)]


def _dist(values: List[float]) -> Dict[str, Any]:
    if not values:
        return {"n": 0}
    s = sorted(values)
    return {"n": len(s), "min": round(s[0], 4), "median": round(s[len(s) // 2], 4), "mean": round(sum(s) / len(s), 4), "max": round(s[-1], 4)}


def _rate(values: List[bool]):
    return round(sum(1 for v in values if v) / len(values), 4) if values else None


def _ratio(a: int, b: int):
    return round(a / b, 4) if b else None


def _mean(values: List[float]):
    return round(sum(values) / len(values), 4) if values else None
