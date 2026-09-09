"""STEP 28: Answer <-> Rubric Alignment tests.

Unit tests use a deterministic topic-based fake embedder so STRONG/PARTIAL/WEAK/NOT_ALIGNED
paths are exercised without downloading a model. API tests hit the real endpoint.
"""

import pytest
from fastapi.testclient import TestClient

from app.config import get_settings
from app.main import app
from app.schemas.rubric_alignment import ALIGNMENT_WEIGHTS, AnalyzeAnswerRubricAlignmentRequest
from app.services.rubric_alignment_analyzer import RubricAlignmentAnalyzer
from app.services.rubric_alignment_validator import RubricAlignmentValidationError, RubricAlignmentValidator

client = TestClient(app)

QUESTION = "Explain database normalization."

ANSWER = (
    "Normalization organizes data to reduce redundancy. "
    "First normal form requires atomic values."
)

FULL_ANSWER = (
    "Normalization organizes data to reduce redundancy. "
    "First normal form requires atomic values and no repeating groups. "
    "Second normal form removes partial dependency on the primary key. "
    "Third normal form removes transitive dependency. "
    "For example a student table with course name can be split into two tables."
)


def _criteria():
    return [
        {"id": 1, "criterion": "Definition", "description": "Defines normalization as organizing data to reduce redundancy.", "max_marks": 2,
         "expected_indicators": ["reduces redundancy", "organizes data"], "sort_order": 1},
        {"id": 2, "criterion": "1NF", "description": "Explains first normal form.", "max_marks": 2,
         "expected_indicators": ["atomic values"], "sort_order": 2},
        {"id": 3, "criterion": "2NF", "description": "Explains second normal form.", "max_marks": 2,
         "expected_indicators": ["partial dependency"], "sort_order": 3},
        {"id": 4, "criterion": "3NF", "description": "Explains third normal form.", "max_marks": 2,
         "expected_indicators": ["transitive dependency"], "sort_order": 4},
        {"id": 5, "criterion": "Example", "description": "Provides a suitable example.", "max_marks": 2,
         "expected_indicators": ["example table"], "sort_order": 5},
    ]


def _payload(answer=ANSWER, **overrides):
    base = {
        "student_answer": {"id": 101, "text": answer},
        "question": {"id": 5, "text": QUESTION, "total_marks": 10},
        "rubric": {"id": 20, "version": 1, "total_marks": 10, "criteria": _criteria()},
    }
    base.update(overrides)
    return base


TOPICS = ["redundancy", "atomic", "partial", "transitive", "example"]


class TopicHf:
    """Embeds text as a topic-presence vector so cosine is 1.0 when the same topic appears, else ~0."""

    model_name = "fake/topic-embedder"

    def generate_batch_embeddings(self, texts):
        out = []
        for t in texts:
            low = t.lower()
            vec = [1.0 if topic in low else 0.0 for topic in TOPICS]
            # text-specific tiny component so unrelated texts are not identical zero vectors
            bucket = sum(ord(ch) for ch in low) % 64
            vec.extend([0.01 if i == bucket else 0.0 for i in range(64)])
            out.append(vec)
        return out


class BrokenHf:
    model_name = "broken"

    def generate_batch_embeddings(self, texts):
        raise RuntimeError("model not loaded")


def _by_id(result, cid):
    return next(c for c in result["criterion_alignments"] if c["rubric_criterion_id"] == cid)


def _weighted(result):
    total = sum(c["max_marks"] for c in result["criterion_alignments"])
    return round(sum(c["alignment_score"] * c["max_marks"] for c in result["criterion_alignments"]) / total * 100, 2)


# ------------------------------------------------------------------ API tests


def test_alignment_api_returns_valid_result():
    response = client.post("/api/v1/analyze-answer-rubric-alignment", json=_payload())
    assert response.status_code == 200, response.text
    body = response.json()
    assert body["status"] == "success"
    assert 0 <= body["overall_alignment_score"] <= 100
    assert body["overall_alignment_status"] in ("STRONG", "PARTIAL", "WEAK", "NOT_ALIGNED")
    assert len(body["criterion_alignments"]) == 5
    assert _weighted(body) == body["overall_alignment_score"]
    assert sum(body["counts"].values()) == 5
    for c in body["criterion_alignments"]:
        assert c["alignment_status"] in ALIGNMENT_WEIGHTS
        assert c["alignment_score"] == ALIGNMENT_WEIGHTS[c["alignment_status"]]
        assert c["explanation"]
        for e in c["evidence"]:
            assert e.rstrip("…") in ANSWER or e in ANSWER
    assert "Faculty review" in body["metadata"]["disclaimer"]
    assert body["metadata"]["thresholds"] == {"strong": 0.75, "partial": 0.55, "weak": 0.35}
    assert "confidence" not in body


def test_alignment_api_full_answer_scores_higher_than_partial_answer():
    partial = client.post("/api/v1/analyze-answer-rubric-alignment", json=_payload(ANSWER)).json()
    full = client.post("/api/v1/analyze-answer-rubric-alignment", json=_payload(FULL_ANSWER)).json()
    assert full["overall_alignment_score"] > partial["overall_alignment_score"]
    assert partial["counts"]["not_aligned"] + partial["counts"]["weak"] >= 2


def test_alignment_api_missing_answer_rejected():
    payload = _payload()
    del payload["student_answer"]
    assert client.post("/api/v1/analyze-answer-rubric-alignment", json=payload).status_code == 422


def test_alignment_api_empty_answer_rejected():
    assert client.post("/api/v1/analyze-answer-rubric-alignment", json=_payload("   ")).status_code == 422


def test_alignment_api_missing_rubric_rejected():
    payload = _payload()
    del payload["rubric"]
    assert client.post("/api/v1/analyze-answer-rubric-alignment", json=payload).status_code == 422


def test_alignment_api_empty_criteria_rejected():
    payload = _payload()
    payload["rubric"]["criteria"] = []
    assert client.post("/api/v1/analyze-answer-rubric-alignment", json=payload).status_code == 422


def test_alignment_api_invalid_criterion_marks_rejected():
    payload = _payload()
    payload["rubric"]["criteria"][0]["max_marks"] = -1
    assert client.post("/api/v1/analyze-answer-rubric-alignment", json=payload).status_code == 422
    payload = _payload()
    payload["rubric"]["criteria"][0]["max_marks"] = 5  # criteria no longer sum to rubric total
    assert client.post("/api/v1/analyze-answer-rubric-alignment", json=payload).status_code == 422


def test_alignment_api_missing_criterion_id_rejected():
    payload = _payload()
    del payload["rubric"]["criteria"][0]["id"]
    assert client.post("/api/v1/analyze-answer-rubric-alignment", json=payload).status_code == 422


def test_alignment_api_duplicate_criterion_id_rejected():
    payload = _payload()
    payload["rubric"]["criteria"][1]["id"] = 1
    assert client.post("/api/v1/analyze-answer-rubric-alignment", json=payload).status_code == 422


def test_alignment_api_rubric_total_optional():
    payload = _payload()
    del payload["rubric"]["total_marks"]
    del payload["question"]["total_marks"]
    assert client.post("/api/v1/analyze-answer-rubric-alignment", json=payload).status_code == 200


def test_alignment_api_engine_failure_returns_clean_500(monkeypatch):
    def boom(self, request):
        raise RuntimeError("secret path and student answer text")

    monkeypatch.setattr(RubricAlignmentAnalyzer, "analyze", boom)
    response = client.post("/api/v1/analyze-answer-rubric-alignment", json=_payload())
    assert response.status_code == 500
    assert "student answer text" not in response.text


def test_alignment_api_validation_failure_returns_clean_500(monkeypatch):
    def invalid(self, request):
        raise RubricAlignmentValidationError("mismatch")

    monkeypatch.setattr(RubricAlignmentAnalyzer, "analyze", invalid)
    response = client.post("/api/v1/analyze-answer-rubric-alignment", json=_payload())
    assert response.status_code == 500
    assert "valid rubric alignment" in response.json()["detail"]


def test_alignment_api_timeout_returns_clean_500(monkeypatch):
    def slow(self, request):
        raise TimeoutError("timed out")

    monkeypatch.setattr(RubricAlignmentAnalyzer, "analyze", slow)
    assert client.post("/api/v1/analyze-answer-rubric-alignment", json=_payload()).status_code == 500


def test_alignment_api_malformed_engine_output_rejected_by_schema(monkeypatch):
    def malformed(self, request):
        return {"status": "success", "overall_alignment_score": 62.5, "overall_alignment_status": "PARTIAL",
                "unweighted_alignment_score": 62.5, "counts": {}, "summary": "x", "criterion_alignments": [],
                "metadata": {"model": "x"}}

    monkeypatch.setattr(RubricAlignmentAnalyzer, "analyze", malformed)
    assert client.post("/api/v1/analyze-answer-rubric-alignment", json=_payload()).status_code == 500


# ------------------------------------------------------------- classification


def test_classification_strong_partial_weak_not_aligned():
    analyzer = RubricAlignmentAnalyzer(hf_service=TopicHf(), text_generation_service=None)
    criteria = [
        # both indicators present -> STRONG
        {"id": 1, "criterion": "Definition", "description": "Defines normalization and redundancy.", "max_marks": 2,
         "expected_indicators": ["reduces redundancy", "organizes data to reduce redundancy"]},
        # one of two indicators present, description matches -> 0.65*0.5 + 0.35*1 = 0.675 -> PARTIAL
        {"id": 2, "criterion": "1NF", "description": "Explains atomic values in first normal form.", "max_marks": 2,
         "expected_indicators": ["atomic values", "no repeating groups xyz"]},
        # description only matches, indicator absent -> 0.35 -> WEAK
        {"id": 3, "criterion": "Redundancy purpose", "description": "Discusses redundancy.", "max_marks": 2,
         "expected_indicators": ["storage anomalies qqq"]},
        # nothing matches -> NOT_ALIGNED
        {"id": 4, "criterion": "3NF", "description": "Explains transitive dependency.", "max_marks": 2,
         "expected_indicators": ["transitive dependency"]},
        {"id": 5, "criterion": "Example", "description": "Provides an example.", "max_marks": 2,
         "expected_indicators": ["example table"]},
    ]
    payload = _payload()
    payload["rubric"]["criteria"] = criteria
    result = analyzer.analyze(AnalyzeAnswerRubricAlignmentRequest(**payload))

    assert _by_id(result, 1)["alignment_status"] == "STRONG"
    assert _by_id(result, 2)["alignment_status"] == "PARTIAL"
    assert _by_id(result, 3)["alignment_status"] == "WEAK"
    assert _by_id(result, 4)["alignment_status"] == "NOT_ALIGNED"
    assert _by_id(result, 5)["alignment_status"] == "NOT_ALIGNED"

    assert result["counts"] == {"strong": 1, "partial": 1, "weak": 1, "not_aligned": 2}
    # (1 + 0.5 + 0.25 + 0 + 0) / 5 * 100 = 35 ; equal marks so weighted == unweighted
    assert result["overall_alignment_score"] == 35.0
    assert result["unweighted_alignment_score"] == 35.0
    assert result["overall_alignment_status"] == "WEAK"

    assert "atomic values" in " ".join(_by_id(result, 2)["evidence"]).lower()
    assert any("no repeating groups" in m for m in _by_id(result, 2)["missing_elements"])
    assert _by_id(result, 4)["evidence"] == []
    assert _by_id(result, 4)["missing_elements"] == ["No evidence found for: transitive dependency."]
    assert "verify correctness" in _by_id(result, 1)["explanation"]
    assert result["metadata"]["method"] == "semantic_and_rubric_alignment"
    assert result["metadata"]["embedding_model"] == "fake/topic-embedder"


def test_mark_weighted_score_differs_from_unweighted():
    analyzer = RubricAlignmentAnalyzer(hf_service=TopicHf(), text_generation_service=None)
    payload = _payload()
    payload["rubric"]["total_marks"] = 10
    payload["rubric"]["criteria"] = [
        {"id": 1, "criterion": "Definition", "description": "redundancy", "max_marks": 8, "expected_indicators": ["reduces redundancy"]},
        {"id": 2, "criterion": "3NF", "description": "transitive", "max_marks": 2, "expected_indicators": ["transitive dependency"]},
    ]
    result = analyzer.analyze(AnalyzeAnswerRubricAlignmentRequest(**payload))
    assert _by_id(result, 1)["alignment_status"] == "STRONG"
    assert _by_id(result, 2)["alignment_status"] == "NOT_ALIGNED"
    assert result["overall_alignment_score"] == 80.0   # 8/10 marks aligned
    assert result["unweighted_alignment_score"] == 50.0
    assert result["overall_alignment_status"] == "STRONG"


def test_full_and_zero_alignment_scores():
    analyzer = RubricAlignmentAnalyzer(hf_service=TopicHf(), text_generation_service=None)
    full = analyzer.analyze(AnalyzeAnswerRubricAlignmentRequest(**_payload(FULL_ANSWER)))
    assert full["overall_alignment_score"] == 100.0
    assert full["overall_alignment_status"] == "STRONG"
    assert full["missing_elements"] == []
    assert len(full["strengths"]) == 5

    zero = analyzer.analyze(AnalyzeAnswerRubricAlignmentRequest(**_payload("The weather is nice today and I like music.")))
    assert zero["overall_alignment_score"] == 0.0
    assert zero["overall_alignment_status"] == "NOT_ALIGNED"
    assert zero["counts"]["not_aligned"] == 5
    assert all(c["evidence"] == [] for c in zero["criterion_alignments"])


def test_decimal_weighted_score():
    analyzer = RubricAlignmentAnalyzer(hf_service=TopicHf(), text_generation_service=None)
    payload = _payload()
    payload["rubric"]["total_marks"] = 7
    payload["rubric"]["criteria"] = [
        {"id": 1, "criterion": "Definition", "description": "redundancy", "max_marks": 3, "expected_indicators": ["reduces redundancy"]},
        {"id": 2, "criterion": "1NF", "description": "atomic", "max_marks": 1.5, "expected_indicators": ["atomic values"]},
        {"id": 3, "criterion": "3NF", "description": "transitive", "max_marks": 2.5, "expected_indicators": ["transitive dependency"]},
    ]
    result = analyzer.analyze(AnalyzeAnswerRubricAlignmentRequest(**payload))
    assert result["overall_alignment_score"] == 64.29  # 4.5 / 7
    assert result["unweighted_alignment_score"] == 66.67


def test_lexical_fallback_when_embeddings_fail():
    analyzer = RubricAlignmentAnalyzer(hf_service=BrokenHf(), text_generation_service=None)
    result = analyzer.analyze(AnalyzeAnswerRubricAlignmentRequest(**_payload()))
    assert result["metadata"]["method"] == "lexical_rubric_alignment"
    assert result["metadata"]["embedding_model"] is None
    assert _by_id(result, 1)["alignment_status"] in ("STRONG", "PARTIAL")
    assert _by_id(result, 3)["alignment_status"] == "NOT_ALIGNED"
    assert "embedding model unavailable" in result["summary"]


def test_generative_refinement_is_optional_and_never_changes_scores():
    class FakeGen:
        is_configured = True
        model_name = "fake/flan-t5-small"

        def generate_lines(self, prompt, max_new_tokens=None):
            return ["The response mentions redundancy reduction as the purpose of normalization."]

    class FailingGen(FakeGen):
        def generate_lines(self, prompt, max_new_tokens=None):
            raise TimeoutError("timed out")

    base = RubricAlignmentAnalyzer(hf_service=TopicHf(), text_generation_service=None).analyze(AnalyzeAnswerRubricAlignmentRequest(**_payload()))
    refined = RubricAlignmentAnalyzer(hf_service=TopicHf(), text_generation_service=FakeGen()).analyze(AnalyzeAnswerRubricAlignmentRequest(**_payload()))
    failed = RubricAlignmentAnalyzer(hf_service=TopicHf(), text_generation_service=FailingGen()).analyze(AnalyzeAnswerRubricAlignmentRequest(**_payload()))

    assert refined["metadata"]["generative_model_used"] is True
    assert refined["metadata"]["model"] == "fake/flan-t5-small"
    assert "redundancy reduction" in _by_id(refined, 1)["explanation"]
    assert failed["metadata"]["generative_model_used"] is False
    for r in (refined, failed):
        assert r["overall_alignment_score"] == base["overall_alignment_score"]
        assert [c["alignment_status"] for c in r["criterion_alignments"]] == [c["alignment_status"] for c in base["criterion_alignments"]]


def test_thresholds_are_configurable(monkeypatch):
    settings = get_settings()
    monkeypatch.setattr(settings, "rubric_alignment_strong_threshold", 0.9)
    monkeypatch.setattr(settings, "rubric_alignment_partial_threshold", 0.7)
    analyzer = RubricAlignmentAnalyzer(hf_service=TopicHf(), text_generation_service=None)
    payload = _payload()
    payload["rubric"]["criteria"] = [{"id": 1, "criterion": "1NF", "description": "Explains atomic values.", "max_marks": 10,
                                      "expected_indicators": ["atomic values", "no repeating groups xyz"]}]
    payload["rubric"]["total_marks"] = 10
    result = analyzer.analyze(AnalyzeAnswerRubricAlignmentRequest(**payload))
    # 0.675 signal is PARTIAL by default but WEAK under the stricter thresholds
    assert _by_id(result, 1)["alignment_status"] == "WEAK"
    assert result["metadata"]["thresholds"]["strong"] == 0.9


def test_invalid_threshold_ordering_rejected(monkeypatch):
    settings = get_settings()
    monkeypatch.setattr(settings, "rubric_alignment_weak_threshold", 0.9)
    with pytest.raises(ValueError):
        RubricAlignmentAnalyzer(hf_service=TopicHf())


# --------------------------------------------------------------- validator


def _valid_result():
    return {
        "overall_alignment_score": 55.0,
        "unweighted_alignment_score": 55.0,
        "overall_alignment_status": "PARTIAL",
        "criterion_alignments": [
            {"rubric_criterion_id": 1, "alignment_status": "STRONG", "alignment_score": 1.0, "explanation": "ok", "evidence": [], "missing_elements": []},
            {"rubric_criterion_id": 2, "alignment_status": "STRONG", "alignment_score": 1.0, "explanation": "ok", "evidence": [], "missing_elements": []},
            {"rubric_criterion_id": 3, "alignment_status": "PARTIAL", "alignment_score": 0.5, "explanation": "ok", "evidence": [], "missing_elements": []},
            {"rubric_criterion_id": 4, "alignment_status": "WEAK", "alignment_score": 0.25, "explanation": "ok", "evidence": [], "missing_elements": []},
            {"rubric_criterion_id": 5, "alignment_status": "NOT_ALIGNED", "alignment_score": 0.0, "explanation": "ok", "evidence": [], "missing_elements": []},
        ],
    }


def test_validator_accepts_spec_example():
    ok, errors = RubricAlignmentValidator.validate(_valid_result(), _criteria())
    assert ok, errors


def test_validator_rejects_score_mismatch_and_bad_status():
    r = _valid_result()
    r["overall_alignment_score"] = 70
    ok, errors = RubricAlignmentValidator.validate(r, _criteria())
    assert not ok and any("mark-weighted" in e for e in errors)

    r = _valid_result()
    r["criterion_alignments"][0]["alignment_status"] = "CORRECT"
    ok, errors = RubricAlignmentValidator.validate(r, _criteria())
    assert not ok and any("unknown alignment_status" in e for e in errors)

    r = _valid_result()
    r["criterion_alignments"][0]["alignment_score"] = 0.7
    ok, errors = RubricAlignmentValidator.validate(r, _criteria())
    assert not ok and any("status weight" in e for e in errors)


def test_validator_rejects_missing_or_unknown_criteria():
    r = _valid_result()
    r["criterion_alignments"][0]["rubric_criterion_id"] = 99
    ok, errors = RubricAlignmentValidator.validate(r, _criteria())
    assert not ok
    assert any("unknown rubric criterion" in e for e in errors)
    assert any("missing for rubric criteria" in e for e in errors)

    r = _valid_result()
    r["criterion_alignments"].pop()
    with pytest.raises(RubricAlignmentValidationError):
        RubricAlignmentValidator.assert_valid(r, _criteria())
