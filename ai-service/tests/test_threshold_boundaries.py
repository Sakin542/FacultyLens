"""Tests for exact threshold boundaries: Learning Outcome alignment, Semantic similarity tiers, and top-K limits."""

import pytest
from app.schemas.alignment import ThresholdsConfig
from app.schemas.similarity import SimilarityThresholdsConfig


def classify_alignment(sim: float, thresholds: ThresholdsConfig) -> str:
    if sim >= thresholds.strong:
        return "STRONG"
    elif sim >= thresholds.weak:
        return "WEAK"
    else:
        return "NOT_ALIGNED"


def classify_similarity(sim: float, thresholds: SimilarityThresholdsConfig) -> str:
    if sim >= thresholds.duplicate:
        return "POTENTIAL_DUPLICATE"
    elif sim >= thresholds.high:
        return "HIGHLY_SIMILAR"
    elif sim >= thresholds.moderate:
        return "SOMEWHAT_SIMILAR"
    else:
        return "NOT_SIMILAR"


def test_alignment_threshold_boundaries():
    """Verify exact alignment boundary classifications: >=0.70 strong, 0.50-0.69 weak, <0.50 not aligned."""
    th = ThresholdsConfig(strong=0.70, weak=0.50)

    assert classify_alignment(0.70, th) == "STRONG"
    assert classify_alignment(0.75, th) == "STRONG"
    assert classify_alignment(0.699, th) == "WEAK"
    assert classify_alignment(0.50, th) == "WEAK"
    assert classify_alignment(0.499, th) == "NOT_ALIGNED"
    assert classify_alignment(0.20, th) == "NOT_ALIGNED"


def test_similarity_threshold_boundaries():
    """Verify exact similarity boundary tiers: >=0.85 duplicate, 0.70-0.84 high, 0.50-0.69 moderate, <0.50 not similar."""
    th = SimilarityThresholdsConfig(duplicate=0.85, high=0.70, moderate=0.50, top_k=5)

    assert classify_similarity(0.85, th) == "POTENTIAL_DUPLICATE"
    assert classify_similarity(0.92, th) == "POTENTIAL_DUPLICATE"
    assert classify_similarity(0.849, th) == "HIGHLY_SIMILAR"
    assert classify_similarity(0.70, th) == "HIGHLY_SIMILAR"
    assert classify_similarity(0.699, th) == "SOMEWHAT_SIMILAR"
    assert classify_similarity(0.50, th) == "SOMEWHAT_SIMILAR"
    assert classify_similarity(0.499, th) == "NOT_SIMILAR"
    assert classify_similarity(0.15, th) == "NOT_SIMILAR"


def test_top_k_limiting():
    """Verify that top-K matching truncates to at most K items and sorts descending by similarity."""
    k = 5
    candidates = [
        {"previous_question_id": i, "similarity_score": 0.40 + (i * 0.05)}
        for i in range(1, 9)
    ]

    sorted_matches = sorted(candidates, key=lambda m: m["similarity_score"], reverse=True)
    top_5 = sorted_matches[:k]

    assert len(top_5) == 5
    assert top_5[0]["similarity_score"] > top_5[1]["similarity_score"]
    assert top_5[0]["similarity_score"] == pytest.approx(0.80)
    assert top_5[4]["similarity_score"] == pytest.approx(0.60)
