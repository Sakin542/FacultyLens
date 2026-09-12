"""Semantic similarity (pair bands + rank correlation) and question-bank top-K retrieval."""

from __future__ import annotations

from collections import Counter, defaultdict
from typing import Any, Dict, List, Tuple

from app.schemas.similarity import CurrentQuestionItem, PreviousQuestionItem, SimilarityThresholdsConfig
from app.services.semantic_similarity_analyzer import SemanticSimilarityAnalyzer
from app.services.threshold_bands import classify_similarity
from evaluation import metrics as M
from evaluation.common import ComponentResult, finalize, load_dataset, timed, truncate
from evaluation.components import Context

BANDS = ["POTENTIAL_DUPLICATE", "HIGHLY_SIMILAR", "SOMEWHAT_SIMILAR", "NOT_SIMILAR"]


def evaluate(ctx: Context) -> List[ComponentResult]:
    return [_pairs(ctx), _retrieval(ctx)]


def _pairs(ctx: Context) -> ComponentResult:
    res = ComponentResult(component="SEMANTIC_SIMILARITY", split="ALL(pairs)")
    analyzer = SemanticSimilarityAnalyzer(hf_service=ctx.hf)
    settings = ctx.settings
    th = SimilarityThresholdsConfig(duplicate=settings.similarity_duplicate_threshold, high=settings.similarity_high_threshold, moderate=settings.similarity_moderate_threshold, top_k=1)
    pairs = load_dataset("similarity_pairs")["pairs"]
    latencies: List[float] = []
    outcomes: List[Tuple[str, str]] = []
    model_scores: List[float] = []
    expert_scores: List[float] = []
    by_band: Dict[str, List[float]] = defaultdict(list)
    hard_negatives: List[Dict[str, Any]] = []
    for p in pairs:
        try:
            out, ms = timed(analyzer.analyze, [CurrentQuestionItem(id="a", text=p["question_a"])], [PreviousQuestionItem(id="b", text=p["question_b"])], thresholds=th, top_k=1)
        except Exception as exc:
            res.failure_count += 1
            res.errors.append({"id": p["id"], "error_type": "MODEL_FAILURE", "note": type(exc).__name__})
            continue
        latencies.append(ms)
        r = out["results"][0]
        score, predicted, expected = r["max_similarity_score"], r["max_similarity_status"], p["expected_relationship"]
        outcomes.append((expected, predicted))
        model_scores.append(score)
        expert_scores.append(p["expert_score"])
        by_band[expected].append(score)
        if p.get("hard_negative"):
            hard_negatives.append({"id": p["id"], "reason": p["hard_negative"], "score": score, "predicted": predicted})
        if predicted != expected:
            direction = "FALSE_SIMILARITY" if BANDS.index(predicted) < BANDS.index(expected) else "MISSED_SIMILARITY"
            res.errors.append({"id": p["id"], "question_a": truncate(p["question_a"], 100), "question_b": truncate(p["question_b"], 100), "expected": expected, "actual": predicted, "score": score,
                               "expert_score": p["expert_score"], "adjacent_band": abs(BANDS.index(predicted) - BANDS.index(expected)) == 1, "error_type": direction})
        ctx.record("SEMANTIC_SIMILARITY", {"id": p["id"], "expected": expected, "predicted": predicted, "score": score, "expert_score": p["expert_score"]})

    report = M.classification_report([e for e, _ in outcomes], [p for _, p in outcomes], BANDS)
    dup = _binary(outcomes, {"POTENTIAL_DUPLICATE"})
    flag = _binary(outcomes, {"POTENTIAL_DUPLICATE", "HIGHLY_SIMILAR"})
    sweep = []
    raw = list(zip([e for e, _ in outcomes], model_scores))
    for dup_t in (0.70, 0.75, 0.80, 0.85, 0.90):
        preds = [classify_similarity(s, dup_t, min(th.high, dup_t - 0.05), th.moderate) for _, s in raw]
        rep = M.classification_report([e for e, _ in raw], preds, BANDS)
        d = _binary(list(zip([e for e, _ in raw], preds)), {"POTENTIAL_DUPLICATE"})
        sweep.append({"duplicate_threshold": dup_t, "band_accuracy": rep["accuracy"], "duplicate_f1": d["f1"], "duplicate_precision": d["precision"], "duplicate_recall": d["recall"], "production": dup_t == th.duplicate})

    res.sample_size = len(outcomes)
    res.latency = M.latency_summary(latencies)
    res.metrics = {
        "spearman": M.spearman(model_scores, expert_scores), "pearson": M.pearson(model_scores, expert_scores),
        "band_accuracy": report["accuracy"], "band_accuracy_ci95": M.wilson_interval(sum(1 for e, p in outcomes if e == p), len(outcomes)), "band_macro_f1": report["macro_f1"], "band_weighted_f1": report["weighted_f1"],
        "adjacent_band_accuracy": round(sum(1 for e, p in outcomes if abs(BANDS.index(e) - BANDS.index(p)) <= 1) / len(outcomes), 4) if outcomes else None,
        "duplicate_precision": dup["precision"], "duplicate_recall": dup["recall"], "duplicate_f1": dup["f1"],
        "flag_precision": flag["precision"], "flag_recall": flag["recall"], "flag_f1": flag["f1"],
        "hard_negatives_max_score": round(max((h["score"] for h in hard_negatives), default=0.0), 4), "hard_negatives_false_flags": sum(1 for h in hard_negatives if h["predicted"] != "NOT_SIMILAR"),
    }
    res.structured = {
        "confusion_matrix": report["confusion_matrix"], "per_class": report["per_class"], "score_distribution_by_expected_band": {b: _dist(v) for b, v in by_band.items()},
        "threshold_sweep": sweep, "production_thresholds": {"duplicate": th.duplicate, "high": th.high, "moderate": th.moderate}, "hard_negatives": hard_negatives,
        "error_transitions": dict(Counter(f"{e}->{p}" for e, p in outcomes if e != p)),
    }
    res.notes += [
        "Model: MiniLM cosine (sentence-transformers/all-MiniLM-L6-v2). 'flag' = POTENTIAL_DUPLICATE or HIGHLY_SIMILAR (what faculty are shown as a reuse warning). This is similarity ranking, not exact-duplicate detection.",
        "Spearman/Pearson correlate the model's cosine with the annotator's 0-1 closeness judgement; band metrics apply the production thresholds to the model score. The sweep is informational only.",
    ]
    ci = M.bootstrap_ci(list(zip(model_scores, expert_scores)), lambda items: M.spearman([a for a, _ in items], [b for _, b in items]), seed=ctx.seed)
    return finalize(res, ctx.gates, ci)


def _retrieval(ctx: Context) -> ComponentResult:
    res = ComponentResult(component="QUESTION_BANK_RETRIEVAL", split="ALL(bank)")
    analyzer = SemanticSimilarityAnalyzer(hf_service=ctx.hf)
    data = load_dataset("retrieval")
    bank = [PreviousQuestionItem(id=q["id"], text=q["question"]) for q in ctx.catalogue.questions.values()]
    latencies: List[float] = []
    per_k: Dict[int, Dict[str, List[float]]] = {k: {"precision": [], "recall": [], "hit": []} for k in (1, 3, 5)}
    mrr: List[float] = []
    ndcg5: List[float] = []
    for q in data["queries"]:
        relevant = {k: float(v) for k, v in q["relevant"].items()}
        try:
            out, ms = timed(analyzer.analyze, [CurrentQuestionItem(id=q["id"], text=q["query"])], bank, top_k=5)
        except Exception as exc:
            res.failure_count += 1
            res.errors.append({"id": q["id"], "error_type": "MODEL_FAILURE", "note": type(exc).__name__})
            continue
        latencies.append(ms)
        ranked = [str(m["previous_question_id"]) for m in out["results"][0]["matches"]]
        for k in per_k:
            per_k[k]["precision"].append(M.precision_at_k(ranked, relevant, k))
            per_k[k]["recall"].append(M.recall_at_k(ranked, relevant, k))
            per_k[k]["hit"].append(1.0 if M.hit_at_k(ranked, relevant, k) else 0.0)
        rr = M.reciprocal_rank(ranked, relevant) or 0.0
        mrr.append(rr)
        ndcg5.append(M.ndcg_at_k(ranked, relevant, 5) or 0.0)
        top_relevant = {k for k, v in relevant.items() if v >= 2}
        if top_relevant and ranked[:1] and ranked[0] not in top_relevant:
            res.errors.append({"id": q["id"], "query": q["query"], "expected_top": sorted(top_relevant), "actual_top3": ranked[:3], "scores_top3": [m["similarity_score"] for m in out["results"][0]["matches"][:3]],
                               "error_type": "MISSED_SIMILARITY" if rr == 0 else "FALSE_SIMILARITY", "note": "paraphrase not ranked first" if rr > 0 else "no relevant item in top-5"})
        ctx.record("QUESTION_BANK_RETRIEVAL", {"id": q["id"], "ranked": ranked, "relevant": relevant, "rr": rr})

    n = len(mrr)
    res.sample_size = n
    res.latency = M.latency_summary(latencies)
    res.metrics = {"mrr": _mean(mrr), "ndcg_at_5": _mean(ndcg5)}
    for k in per_k:
        res.metrics[f"precision_at_{k}"] = _mean(per_k[k]["precision"])
        res.metrics[f"recall_at_{k}"] = _mean(per_k[k]["recall"])
        res.metrics[f"hit_rate_at_{k}"] = _mean(per_k[k]["hit"])
    res.metrics["recall_at_5_ci95"] = M.wilson_interval(sum(1 for v in per_k[5]["recall"] if v is not None and v >= 0.999), n)
    res.metrics["full_recall_at_5_rate"] = _mean([1.0 if (v is not None and v >= 0.999) else 0.0 for v in per_k[5]["recall"]])
    res.metrics["bank_size"] = len(bank)
    res.notes += [
        "Bank = all 144 labelled questions; 24 hand-written queries with graded relevance (2 = paraphrase, 1 = same concept).",
        "Precision@K is bounded by the number of relevant items (most queries have 1-3), so recall@K, hit-rate@K and MRR are the informative numbers. recall_at_5_ci95 is the Wilson interval of the share of queries with full recall@5.",
    ]
    return finalize(res, ctx.gates, res.metrics["recall_at_5_ci95"])


def _binary(outcomes: List[Tuple[str, str]], positive: set) -> Dict[str, Any]:
    tp = sum(1 for e, p in outcomes if e in positive and p in positive)
    fp = sum(1 for e, p in outcomes if e not in positive and p in positive)
    fn = sum(1 for e, p in outcomes if e in positive and p not in positive)
    precision = tp / (tp + fp) if (tp + fp) else None
    recall = tp / (tp + fn) if (tp + fn) else None
    f1 = 2 * precision * recall / (precision + recall) if precision and recall else (0.0 if (tp + fp + fn) else None)
    return {"precision": _r(precision), "recall": _r(recall), "f1": _r(f1), "tp": tp, "fp": fp, "fn": fn}


def _r(v):
    return round(v, 4) if v is not None else None


def _mean(values: List[Any]):
    vals = [v for v in values if v is not None]
    return round(sum(vals) / len(vals), 4) if vals else None


def _dist(values: List[float]) -> Dict[str, Any]:
    if not values:
        return {"n": 0}
    s = sorted(values)
    return {"n": len(s), "min": round(s[0], 4), "median": round(s[len(s) // 2], 4), "mean": round(sum(s) / len(s), 4), "max": round(s[-1], 4)}
