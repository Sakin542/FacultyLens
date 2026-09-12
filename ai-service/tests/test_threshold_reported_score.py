"""BUG-006 regression: the status of a match must be derived from the score that is
reported (4 dp). Before the fix a raw cosine of 0.84996 was reported as 0.85 but
labelled HIGHLY_SIMILAR, and a raw 0.69996 was reported as 0.70 but labelled WEAK.
"""

import math

import pytest

from app.schemas.alignment import LearningOutcomeItem, QuestionItem, ThresholdsConfig
from app.schemas.similarity import CurrentQuestionItem, PreviousQuestionItem, SimilarityThresholdsConfig
from app.services.lo_matcher import LearningOutcomeMatcher
from app.services.semantic_similarity_analyzer import SemanticSimilarityAnalyzer
from app.services.threshold_bands import classify_alignment, classify_similarity


def _unit_vectors_with_cosine(cos: float):
    """Two 2-D unit vectors whose cosine similarity is exactly ``cos``."""
    return [1.0, 0.0], [cos, math.sqrt(max(0.0, 1.0 - cos * cos))]


class _StubHF:
    """Returns pre-assigned embeddings by text so cosine similarities are controlled exactly."""

    model_name = "stub"
    is_loaded = True

    def __init__(self, mapping):
        self.mapping = mapping

    def generate_batch_embeddings(self, texts):
        return [self.mapping[t] for t in texts]


@pytest.mark.parametrize(
    "raw, expected",
    [
        (0.84996, "POTENTIAL_DUPLICATE"),  # reported 0.85
        (0.84994, "HIGHLY_SIMILAR"),       # reported 0.8499
        (0.69996, "HIGHLY_SIMILAR"),       # reported 0.70
        (0.69994, "SOMEWHAT_SIMILAR"),     # reported 0.6999
        (0.49996, "SOMEWHAT_SIMILAR"),     # reported 0.50
        (0.49994, "NOT_SIMILAR"),          # reported 0.4999
    ],
)
def test_similarity_band_matches_reported_score(raw, expected):
    assert classify_similarity(raw, 0.85, 0.70, 0.50) == expected


@pytest.mark.parametrize(
    "raw, expected",
    [
        (0.69996, "STRONG"),       # reported 0.70
        (0.69994, "WEAK"),         # reported 0.6999
        (0.49996, "WEAK"),         # reported 0.50
        (0.49994, "NOT_ALIGNED"),  # reported 0.4999
    ],
)
def test_alignment_band_matches_reported_score(raw, expected):
    assert classify_alignment(raw, 0.70, 0.50) == expected


def test_similarity_analyzer_status_is_consistent_with_reported_score():
    cur_vec, prev_vec = _unit_vectors_with_cosine(0.84996)
    hf = _StubHF({"current": cur_vec, "previous": prev_vec})
    analyzer = SemanticSimilarityAnalyzer(hf_service=hf)

    result = analyzer.analyze(
        current_questions=[CurrentQuestionItem(id=1, question_number=1, text="current")],
        previous_questions=[PreviousQuestionItem(id=9, text="previous")],
        thresholds=SimilarityThresholdsConfig(duplicate=0.85, high=0.70, moderate=0.50, top_k=5),
    )

    row = result["results"][0]
    match = row["matches"][0]
    assert match["similarity_score"] == pytest.approx(0.85)
    assert match["similarity_status"] == "POTENTIAL_DUPLICATE"
    assert row["max_similarity_status"] == "POTENTIAL_DUPLICATE"
    assert result["potential_duplicates_count"] == 1
    assert result["highly_similar_count"] == 0


def test_lo_matcher_status_is_consistent_with_reported_score():
    q_vec, lo_vec = _unit_vectors_with_cosine(0.69996)
    hf = _StubHF({"question": q_vec, "LO1: outcome": lo_vec})
    matcher = LearningOutcomeMatcher(hf_service=hf)

    results = matcher.match_questions_to_los(
        questions=[QuestionItem(id=1, number=1, text="question")],
        learning_outcomes=[LearningOutcomeItem(id=1, code="LO1", description="outcome")],
        thresholds=ThresholdsConfig(strong=0.70, weak=0.50),
    )

    row = results[0]
    assert row["similarity_score"] == pytest.approx(0.70)
    assert row["alignment_status"] == "STRONG"
    assert row["matched_learning_outcome"]["alignment_level"] == "STRONG"
