"""STEP 46 — Unsupported certainty, hallucinated confidence, conflicting evidence and
deterministic-calculation protection.

Principle 2: prefer "Potential duplicate based on semantic similarity" / "Review recommended" /
"Insufficient evidence" over "definitely", "exact duplicate", "the student failed", "100% accurate".
LLM text may explain deterministic results but never replaces them.
"""

import pytest

from app.services import safety
from app.services.explainability import FORBIDDEN_PHRASES, deterministic_fallback, validate_explanation
from evaluation import safety_harness as harness
from tests.safety.conftest import cases_in, ids_for, run

CLAIM_CASES = cases_in("UNSUPPORTED_CLAIM")
CONFLICT_CASES = cases_in("CONFLICTING_EVIDENCE")
DETERMINISTIC_CASES = cases_in("DETERMINISTIC_PROTECTION")


@pytest.mark.parametrize("case", CLAIM_CASES, ids=ids_for(CLAIM_CASES))
def test_unsupported_claim_case(case):
    run(case)


@pytest.mark.parametrize("case", CONFLICT_CASES, ids=ids_for(CONFLICT_CASES))
def test_conflicting_evidence_case(case):
    run(case)


@pytest.mark.parametrize("case", DETERMINISTIC_CASES, ids=ids_for(DETERMINISTIC_CASES))
def test_deterministic_protection_case(case):
    run(case)


# ------------------------------------------------------------------ certainty detectors


@pytest.mark.parametrize("text", [
    "This question is definitely unfair.",
    "The student definitely failed.",
    "This is an exact duplicate.",
    "The faculty member graded incorrectly.",
    "This assessment violates accreditation requirements.",
    "The assessment is fully compliant with the university policy.",
    "It is a fact that students will fail this paper.",
])
def test_unsupported_certainty_is_detected(text):
    assert safety.find_unsupported_certainty(text), text


@pytest.mark.parametrize("text", [
    "Potential duplicate based on semantic similarity.",
    "Review recommended based on the available evidence.",
    "Insufficient evidence to determine alignment.",
    "The question has partial semantic overlap with LO-2 (similarity 0.61).",
    "Faculty review required before finalizing.",
])
def test_hedged_language_is_not_flagged(text):
    assert safety.find_unsupported_certainty(text) == []
    assert safety.find_hallucinated_confidence(text) == []


@pytest.mark.parametrize("text", ["95% accurate", "99% confident", "100% correct", "I am completely certain", "confidence: 0.99", "accurate to 98%"])
def test_hallucinated_confidence_is_detected(text):
    assert safety.find_hallucinated_confidence(text), text


@pytest.mark.parametrize("text", ["similarity score = 0.87", "60% of questions target LO1", "the pass mark is 40%", "macro-F1 0.83 on 120 examples"])
def test_legitimate_percentages_and_metrics_are_not_flagged_as_confidence(text):
    assert safety.find_hallucinated_confidence(text) == []


@pytest.mark.parametrize("value,expected", [
    (0.84, 0.84), (84, 0.84), (1, 1.0), (0, 0.0), (100, 1.0),
    (1.5, None), (-0.1, None), (101, None), ("0.9", 0.9), ("high", None), (None, None), (True, None), (float("nan"), None),
])
def test_validate_confidence_normalises_or_rejects(value, expected):
    assert safety.validate_confidence(value) == expected


def test_confidence_unavailable_text_exists():
    assert safety.CONFIDENCE_UNAVAILABLE_TEXT == "Confidence unavailable."


# ------------------------------------------------------------------ conflicting evidence detector

A = {"chunk_id": 1, "document_id": 11, "document_name": "A.pdf", "content": "The final examination total marks = 50."}
B = {"chunk_id": 2, "document_id": 12, "document_name": "B.pdf", "content": "Total marks: 60 for the final examination."}
C = {"chunk_id": 3, "document_id": 13, "document_name": "C.pdf", "content": "Total marks: 50 for the final examination."}


def test_conflict_detected_across_documents_for_asked_subject():
    conflicts = safety.detect_conflicting_evidence([A, B], "What are the total marks for the final examination?")
    assert len(conflicts) == 1
    assert conflicts[0]["subject"] == "mark"
    assert sorted(v["value"] for v in conflicts[0]["values"]) == [50.0, 60.0]
    assert {v["chunk_id"] for v in conflicts[0]["values"]} == {1, 2}


def test_agreeing_documents_are_not_a_conflict():
    assert safety.detect_conflicting_evidence([A, C], "What are the total marks?") == []


def test_conflict_about_another_subject_does_not_block_question():
    assert safety.detect_conflicting_evidence([A, B], "What does the document say about normalization?") == []


def test_conflict_within_one_document_is_not_reported():
    single = {"chunk_id": 9, "document_id": 1, "document_name": "S.pdf", "content": "Midterm marks = 20. Final marks = 50."}
    assert safety.detect_conflicting_evidence([single], "What are the marks?") == []


def test_without_question_all_conflicts_are_listed():
    assert len(safety.detect_conflicting_evidence([A, B])) == 1


# ------------------------------------------------------------------ deterministic protection (STEP 45 validator)


def test_explanation_validator_rejects_numbers_that_contradict_the_calculation():
    facts = {"scores": {"similarity": 0.61}, "labels": {"alignment": "WEAK"}}
    bad = validate_explanation("The similarity score is 0.95, so this is an exact duplicate.", facts)
    assert bad["valid"] is False and bad["violations"]
    fallback = deterministic_fallback("alignment", facts)
    assert fallback and "0.95" not in fallback and "0.61" in fallback
    assert not safety.find_unsupported_certainty(fallback)


def test_forbidden_phrases_cover_step46_principles():
    lowered = [p.lower() for p in FORBIDDEN_PHRASES]
    for phrase in ("exact duplicate", "100% certain", "system prompt", "api key", "students will fail"):
        assert phrase in lowered


def test_grading_engine_is_arithmetic_not_generative():
    """Suggested marks must equal the sum of criterion suggestions produced by the deterministic engine."""
    case = next(c for c in harness.load_cases() if c["id"] == "SAFE-GRADE-001")
    result = harness.execute(case)
    assert result.passed, result.failures
    body = result.body
    assert body["metadata"]["generative_model_used"] is False
    assert abs(sum(c["suggested_marks"] for c in body["criterion_results"]) - body["suggested_marks"]) < 0.005


def test_similarity_verdicts_come_from_cosine_thresholds_not_text():
    res = harness.client().post("/api/v1/analyze-similarity", json={
        "current_questions": [{"id": 1, "text": "Explain the process of converting a relation into Third Normal Form."}],
        "previous_questions": [{"id": 17, "text": "Explain the process of converting a relation into Third Normal Form."}],
        "thresholds": {"duplicate": 0.85, "high": 0.70, "moderate": 0.50, "top_k": 5},
    })
    body = res.json()
    r = body["results"][0]
    assert r["max_similarity_score"] >= 0.85 and r["max_similarity_status"] == "POTENTIAL_DUPLICATE"
    assert "exact duplicate" not in r["reasoning"].lower()
    assert body["method"] == "semantic_embedding_cosine_similarity"
