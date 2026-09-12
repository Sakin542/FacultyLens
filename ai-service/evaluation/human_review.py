"""Aggregates completed human review forms (labels/human_reviews/<COMPONENT>/*.json).

Human quality ratings are reported separately from AI accuracy metrics and from production
faculty-acceptance signals. With zero forms the component's human review is NOT_EVALUATED.
"""

from __future__ import annotations

from collections import defaultdict
from statistics import mean, median
from typing import Any, Dict, List

from evaluation import metrics as M
from evaluation.common import LABELS_DIR, load_json

REVIEWS_DIR = LABELS_DIR / "human_reviews"


def load_reviews(component: str) -> List[Dict[str, Any]]:
    folder = REVIEWS_DIR / component
    if not folder.exists():
        return []
    reviews = []
    for path in sorted(folder.glob("*.json")):
        try:
            data = load_json(path)
        except (OSError, ValueError) as exc:
            print(f"[human_review] skipping unreadable form {path.name}: {type(exc).__name__}")
            continue
        if isinstance(data, dict) and data.get("component") == component and isinstance(data.get("scores"), dict):
            reviews.append(data)
    return reviews


def summarize_reviews(component: str) -> Dict[str, Any]:
    reviews = load_reviews(component)
    if not reviews:
        return {"status": "NOT_EVALUATED", "reviews": 0, "note": "No completed faculty review forms found (labels/HUMAN_REVIEW_FORM.md). Human quality is not inferred from automated checks."}
    dims: Dict[str, List[float]] = defaultdict(list)
    decisions = []
    major = 0
    by_item: Dict[str, List[Dict[str, Any]]] = defaultdict(list)
    for r in reviews:
        for d, v in r["scores"].items():
            if isinstance(v, (int, float)) and 1 <= v <= 5:
                dims[d].append(float(v))
        decisions.append(str(r.get("decision", "")).upper())
        major += bool(r.get("major_error"))
        by_item[str(r.get("item_id"))].append(r)
    all_scores = [v for vals in dims.values() for v in vals]
    agreement = _inter_rater(by_item)
    return {
        "status": "EVALUATED" if len(reviews) >= 5 else "INSUFFICIENT_DATA",
        "reviews": len(reviews), "items": len(by_item), "reviewers": len({r.get("reviewer") for r in reviews}),
        "average_rating": round(mean(all_scores), 3) if all_scores else None, "median_rating": median(all_scores) if all_scores else None,
        "per_dimension": {d: {"mean": round(mean(v), 3), "median": median(v), "n": len(v)} for d, v in sorted(dims.items())},
        "acceptance_rate": round(sum(1 for d in decisions if d in ("ACCEPTED", "REVISED")) / len(decisions), 4),
        "accepted_as_is_rate": round(sum(1 for d in decisions if d == "ACCEPTED") / len(decisions), 4),
        "major_error_rate": round(major / len(reviews), 4),
        "inter_rater": agreement,
    }


def _inter_rater(by_item: Dict[str, List[Dict[str, Any]]]) -> Dict[str, Any]:
    pairs_a, pairs_b, diffs = [], [], []
    for reviews in by_item.values():
        if len(reviews) < 2:
            continue
        a, b = reviews[0], reviews[1]
        pairs_a.append(str(a.get("decision", "")).upper())
        pairs_b.append(str(b.get("decision", "")).upper())
        common = set(a["scores"]) & set(b["scores"])
        diffs += [abs(float(a["scores"][d]) - float(b["scores"][d])) for d in common]
    if not pairs_a:
        return {"status": "NOT_AVAILABLE", "note": "Fewer than two reviewers rated the same item."}
    return {"status": "AVAILABLE", "double_rated_items": len(pairs_a), "decision_kappa": M.cohen_kappa(pairs_a, pairs_b), "mean_abs_score_difference": round(mean(diffs), 3) if diffs else None}
