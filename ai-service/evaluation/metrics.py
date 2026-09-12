"""Metric primitives for the FacultyLens AI accuracy evaluation (STEP 44).

Pure functions only; no model calls. Every metric returns ``None`` when it is undefined for the
inputs (e.g. no samples) instead of a fabricated 0.0, so callers can report INSUFFICIENT_DATA.
"""

from __future__ import annotations

import math
import random
from collections import Counter, defaultdict
from statistics import median
from typing import Any, Callable, Dict, Iterable, List, Optional, Sequence, Tuple

# ------------------------------------------------------------------ classification


def classification_report(expected: Sequence[str], predicted: Sequence[str], labels: Optional[Sequence[str]] = None) -> Dict[str, Any]:
    """Accuracy, macro/weighted P/R/F1, per-class rows and a confusion matrix.

    Macro averages run over the classes that actually occur (expected or predicted), which is the
    STEP 35 convention; ``labels`` only fixes the ordering of the confusion matrix.
    """
    n = len(expected)
    if n == 0 or n != len(predicted):
        return {"accuracy": None, "macro_precision": None, "macro_recall": None, "macro_f1": None, "weighted_f1": None, "per_class": [], "confusion_matrix": {}, "n": 0}
    occurring = [lab for lab in (labels or []) if lab in set(expected) | set(predicted)] or sorted(set(expected) | set(predicted))
    tp: Counter = Counter()
    fp: Counter = Counter()
    fn: Counter = Counter()
    support: Counter = Counter(expected)
    matrix: Dict[str, Dict[str, int]] = {lab: {lab2: 0 for lab2 in occurring} for lab in occurring}
    for e, p in zip(expected, predicted):
        matrix.setdefault(e, {})[p] = matrix.setdefault(e, {}).get(p, 0) + 1
        if e == p:
            tp[e] += 1
        else:
            fp[p] += 1
            fn[e] += 1
    rows = []
    for lab in occurring:
        prec = tp[lab] / (tp[lab] + fp[lab]) if (tp[lab] + fp[lab]) else 0.0
        rec = tp[lab] / (tp[lab] + fn[lab]) if (tp[lab] + fn[lab]) else 0.0
        f1 = 2 * prec * rec / (prec + rec) if (prec + rec) else 0.0
        rows.append({"label": lab, "precision": round(prec, 4), "recall": round(rec, 4), "f1": round(f1, 4), "support": support[lab], "predicted": tp[lab] + fp[lab]})
    macro_p = sum(r["precision"] for r in rows) / len(rows)
    macro_r = sum(r["recall"] for r in rows) / len(rows)
    macro_f1 = sum(r["f1"] for r in rows) / len(rows)
    weighted_f1 = sum(r["f1"] * r["support"] for r in rows) / n
    accuracy = sum(tp.values()) / n
    return {
        "accuracy": round(accuracy, 4), "macro_precision": round(macro_p, 4), "macro_recall": round(macro_r, 4), "macro_f1": round(macro_f1, 4),
        "weighted_f1": round(weighted_f1, 4), "per_class": rows, "confusion_matrix": matrix, "n": n,
    }


def cohen_kappa(a: Sequence[str], b: Sequence[str]) -> Optional[float]:
    n = len(a)
    if n == 0 or n != len(b):
        return None
    agree = sum(1 for x, y in zip(a, b) if x == y) / n
    ca, cb = Counter(a), Counter(b)
    expected = sum(ca[k] * cb.get(k, 0) for k in ca) / (n * n)
    if expected == 1.0:
        return 1.0
    return round((agree - expected) / (1 - expected), 4)


# ------------------------------------------------------------------ intervals


def wilson_interval(successes: int, n: int, z: float = 1.96) -> Optional[Tuple[float, float]]:
    """95% Wilson score interval for a proportion; None when n == 0."""
    if n <= 0:
        return None
    p = successes / n
    denom = 1 + z * z / n
    centre = (p + z * z / (2 * n)) / denom
    half = z * math.sqrt(p * (1 - p) / n + z * z / (4 * n * n)) / denom
    return (round(max(0.0, centre - half), 4), round(min(1.0, centre + half), 4))


def bootstrap_ci(items: Sequence[Any], statistic: Callable[[Sequence[Any]], Optional[float]], n_boot: int = 1000, seed: int = 42, alpha: float = 0.05) -> Optional[Tuple[float, float]]:
    """Percentile bootstrap CI of ``statistic(items)``. Deterministic for a given seed."""
    n = len(items)
    if n < 5:
        return None
    rng = random.Random(seed)  # noqa: S311 - reproducible resampling, not security
    values: List[float] = []
    for _ in range(n_boot):
        sample = [items[rng.randrange(n)] for _ in range(n)]
        v = statistic(sample)
        if v is not None:
            values.append(v)
    if len(values) < 10:
        return None
    values.sort()
    lo = values[int(alpha / 2 * len(values))]
    hi = values[min(len(values) - 1, int((1 - alpha / 2) * len(values)))]
    return (round(lo, 4), round(hi, 4))


# ------------------------------------------------------------------ correlation


def _rank(values: Sequence[float]) -> List[float]:
    order = sorted(range(len(values)), key=lambda i: values[i])
    ranks = [0.0] * len(values)
    i = 0
    while i < len(order):
        j = i
        while j + 1 < len(order) and values[order[j + 1]] == values[order[i]]:
            j += 1
        avg = (i + j) / 2 + 1
        for k in range(i, j + 1):
            ranks[order[k]] = avg
        i = j + 1
    return ranks


def pearson(x: Sequence[float], y: Sequence[float]) -> Optional[float]:
    n = len(x)
    if n < 3 or n != len(y):
        return None
    mx, my = sum(x) / n, sum(y) / n
    sxx = sum((a - mx) ** 2 for a in x)
    syy = sum((b - my) ** 2 for b in y)
    if sxx == 0 or syy == 0:
        return None
    sxy = sum((a - mx) * (b - my) for a, b in zip(x, y))
    return round(sxy / math.sqrt(sxx * syy), 4)


def spearman(x: Sequence[float], y: Sequence[float]) -> Optional[float]:
    if len(x) < 3 or len(x) != len(y):
        return None
    return pearson(_rank(x), _rank(y))


# ------------------------------------------------------------------ ranking


def precision_at_k(ranked: Sequence[str], relevant: Iterable[str], k: int) -> Optional[float]:
    if k <= 0:
        return None
    rel = set(relevant)
    top = list(ranked)[:k]
    return round(sum(1 for r in top if r in rel) / k, 4)


def recall_at_k(ranked: Sequence[str], relevant: Iterable[str], k: int) -> Optional[float]:
    rel = set(relevant)
    if not rel:
        return None
    top = list(ranked)[:k]
    return round(sum(1 for r in top if r in rel) / len(rel), 4)


def hit_at_k(ranked: Sequence[str], relevant: Iterable[str], k: int) -> Optional[bool]:
    rel = set(relevant)
    if not rel:
        return None
    return any(r in rel for r in list(ranked)[:k])


def reciprocal_rank(ranked: Sequence[str], relevant: Iterable[str]) -> Optional[float]:
    rel = set(relevant)
    if not rel:
        return None
    for i, r in enumerate(ranked, start=1):
        if r in rel:
            return round(1.0 / i, 4)
    return 0.0


def ndcg_at_k(ranked: Sequence[str], gains: Dict[str, float], k: int) -> Optional[float]:
    if not gains or k <= 0:
        return None
    dcg = sum(gains.get(r, 0.0) / math.log2(i + 1) for i, r in enumerate(list(ranked)[:k], start=1))
    ideal = sorted(gains.values(), reverse=True)[:k]
    idcg = sum(g / math.log2(i + 1) for i, g in enumerate(ideal, start=1))
    if idcg == 0:
        return None
    return round(dcg / idcg, 4)


# ------------------------------------------------------------------ regression / marks


def error_stats(pairs: Sequence[Tuple[float, float]]) -> Dict[str, Optional[float]]:
    """pairs = (predicted, reference). Returns MAE, RMSE, mean/median signed error, correlation."""
    n = len(pairs)
    if n == 0:
        return {"n": 0, "mae": None, "rmse": None, "mean_error": None, "median_error": None, "pearson": None, "spearman": None}
    errs = [p - r for p, r in pairs]
    return {
        "n": n,
        "mae": round(sum(abs(e) for e in errs) / n, 4),
        "rmse": round(math.sqrt(sum(e * e for e in errs) / n), 4),
        "mean_error": round(sum(errs) / n, 4),
        "median_error": round(median(errs), 4),
        "pearson": pearson([p for p, _ in pairs], [r for _, r in pairs]),
        "spearman": spearman([p for p, _ in pairs], [r for _, r in pairs]),
    }


def within_tolerance_rate(pairs: Sequence[Tuple[float, float]], tolerance: float) -> Optional[float]:
    if not pairs:
        return None
    return round(sum(1 for p, r in pairs if abs(p - r) <= tolerance + 1e-9) / len(pairs), 4)


# ------------------------------------------------------------------ multi-label


def multilabel_report(expected_sets: Sequence[Iterable[str]], predicted_sets: Sequence[Iterable[str]], universe_size: Optional[int] = None) -> Dict[str, Optional[float]]:
    """Micro P/R/F1, macro F1 over labels and Hamming loss for set-valued predictions."""
    n = len(expected_sets)
    if n == 0 or n != len(predicted_sets):
        return {"micro_precision": None, "micro_recall": None, "micro_f1": None, "macro_f1": None, "hamming_loss": None}
    tp = fp = fn = 0
    per_label: Dict[str, Dict[str, int]] = defaultdict(lambda: {"tp": 0, "fp": 0, "fn": 0})
    labels_seen = set()
    for exp, pred in zip(expected_sets, predicted_sets):
        e, p = set(exp), set(pred)
        labels_seen |= e | p
        tp += len(e & p)
        fp += len(p - e)
        fn += len(e - p)
        for lab in e & p:
            per_label[lab]["tp"] += 1
        for lab in p - e:
            per_label[lab]["fp"] += 1
        for lab in e - p:
            per_label[lab]["fn"] += 1
    micro_p = tp / (tp + fp) if (tp + fp) else 0.0
    micro_r = tp / (tp + fn) if (tp + fn) else 0.0
    micro_f1 = 2 * micro_p * micro_r / (micro_p + micro_r) if (micro_p + micro_r) else 0.0
    f1s = []
    for c in per_label.values():
        p_ = c["tp"] / (c["tp"] + c["fp"]) if (c["tp"] + c["fp"]) else 0.0
        r_ = c["tp"] / (c["tp"] + c["fn"]) if (c["tp"] + c["fn"]) else 0.0
        f1s.append(2 * p_ * r_ / (p_ + r_) if (p_ + r_) else 0.0)
    universe = universe_size or max(1, len(labels_seen))
    hamming = (fp + fn) / (n * universe)
    return {"micro_precision": round(micro_p, 4), "micro_recall": round(micro_r, 4), "micro_f1": round(micro_f1, 4), "macro_f1": round(sum(f1s) / len(f1s), 4) if f1s else None, "hamming_loss": round(hamming, 4)}


# ------------------------------------------------------------------ calibration


def calibration_table(confidences: Sequence[float], correct: Sequence[bool], bins: int = 5) -> Dict[str, Any]:
    """Reliability bins + expected calibration error. Confidence must be a real model output."""
    n = len(confidences)
    if n == 0 or n != len(correct):
        return {"ece": None, "bins": []}
    edges = [i / bins for i in range(bins + 1)]
    rows = []
    ece = 0.0
    for lo, hi in zip(edges[:-1], edges[1:]):
        idx = [i for i, c in enumerate(confidences) if (lo <= c < hi) or (hi == 1.0 and c == 1.0)]
        if not idx:
            rows.append({"range": [lo, hi], "count": 0, "mean_confidence": None, "accuracy": None})
            continue
        mean_conf = sum(confidences[i] for i in idx) / len(idx)
        acc = sum(1 for i in idx if correct[i]) / len(idx)
        ece += abs(acc - mean_conf) * len(idx) / n
        rows.append({"range": [lo, hi], "count": len(idx), "mean_confidence": round(mean_conf, 4), "accuracy": round(acc, 4)})
    return {"ece": round(ece, 4), "bins": rows}


# ------------------------------------------------------------------ latency


def latency_summary(ms: Sequence[float]) -> Dict[str, Optional[float]]:
    if not ms:
        return {"count": 0, "mean_ms": None, "p50_ms": None, "p95_ms": None, "max_ms": None}
    s = sorted(ms)
    p95 = s[min(len(s) - 1, int(math.ceil(0.95 * len(s))) - 1)]
    return {"count": len(s), "mean_ms": round(sum(s) / len(s), 2), "p50_ms": round(median(s), 2), "p95_ms": round(p95, 2), "max_ms": round(s[-1], 2)}
