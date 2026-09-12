"""STEP 42 §13/§14 — quality rating bands and weight handling at exact boundaries.

90–100 EXCELLENT · 80–89.99 GOOD · 70–79.99 FAIR · 60–69.99 NEEDS_REVIEW · <60 REQUIRES_ATTENTION.
Weights 20/20/15/15/15/15 must sum to 100; a missing component is excluded and the
remaining weights renormalised (never scored as zero); the result is deterministic.
"""

import pytest

from app.schemas.quality import ComponentScores, QualityWeightsConfig
from app.services.assessment_quality_engine import AssessmentQualityEngine


def _engine():
    # The overall-score step is pure arithmetic; no embedding model is needed.
    return AssessmentQualityEngine.__new__(AssessmentQualityEngine)


def _overall(**scores):
    engine = _engine()
    comps = ComponentScores(**{k: scores.get(k) for k in ComponentScores.model_fields})
    return engine._calculate_overall_score(comps, QualityWeightsConfig())


def _uniform(value):
    return dict(topic_coverage=value, learning_outcome_coverage=value, difficulty_balance=value,
                cognitive_diversity=value, question_diversity=value, marks_distribution=value)


def test_default_weights_sum_to_100():
    w = QualityWeightsConfig()
    assert w.topic + w.lo + w.difficulty + w.cognitive + w.question_diversity + w.marks == pytest.approx(100.0)
    assert (w.topic, w.lo, w.difficulty, w.cognitive, w.question_diversity, w.marks) == (20, 20, 15, 15, 15, 15)


@pytest.mark.parametrize(
    "score, rating",
    [
        (100.0, "EXCELLENT"),
        (90.0, "EXCELLENT"),
        (89.99, "GOOD"),
        (89.5, "GOOD"),
        (80.0, "GOOD"),
        (79.99, "FAIR"),
        (70.0, "FAIR"),
        (69.99, "NEEDS_REVIEW"),
        (60.0, "NEEDS_REVIEW"),
        (59.99, "REQUIRES_ATTENTION"),
        (0.0, "REQUIRES_ATTENTION"),
    ],
)
def test_rating_band_boundaries(score, rating):
    overall, got, _, _ = _overall(**_uniform(score))
    assert overall == pytest.approx(score, abs=0.005)
    assert got == rating


def test_missing_component_is_excluded_not_zeroed():
    # All present components are 80 → GOOD; a missing topic score must not drag the result down.
    scores = _uniform(80.0)
    scores["topic_coverage"] = None
    overall, rating, weights, excluded = _overall(**scores)
    assert excluded == ["topic"]
    assert overall == pytest.approx(80.0)
    assert rating == "GOOD"
    assert sum(weights.values()) == pytest.approx(100.0, abs=0.05)
    assert "topic" not in weights


def test_fewer_than_two_components_is_unavailable_not_a_score():
    overall, rating, weights, excluded = _overall(topic_coverage=95.0)
    assert overall is None and rating == "UNAVAILABLE" and weights == {}
    assert len(excluded) == 5


def test_overall_is_deterministic_for_identical_input():
    scores = dict(topic_coverage=91.3, learning_outcome_coverage=64.2, difficulty_balance=77.7,
                  cognitive_diversity=83.1, question_diversity=58.4, marks_distribution=72.9)
    runs = {(_overall(**scores)[0], _overall(**scores)[1]) for _ in range(25)}
    assert len(runs) == 1
    overall, rating = runs.pop()
    expected = 0.20 * 91.3 + 0.20 * 64.2 + 0.15 * (77.7 + 83.1 + 58.4 + 72.9)
    assert overall == pytest.approx(expected, abs=0.01)
    assert rating == "FAIR"
