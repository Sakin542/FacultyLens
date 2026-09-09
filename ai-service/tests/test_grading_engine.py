"""STEP 27: AI Grading Assistance tests.

Unit tests use a deterministic fake embedding service so they never depend on
downloading a model. The API tests exercise the real endpoint (MiniLM loads
lazily; if unavailable the engine falls back to lexical alignment).
"""

import pytest
from fastapi.testclient import TestClient

from app.main import app
from app.schemas.grading import GradeAnswerRequest
from app.services.grading_engine import GradingEngine
from app.services.grading_validator import GradingValidationError, GradingValidator

client = TestClient(app)

QUESTION = "Explain database normalization and describe 1NF, 2NF and 3NF with an example."

GOOD_ANSWER = (
    "Normalization is the process of organizing data in a database to reduce redundancy and improve data integrity. "
    "First normal form (1NF) requires that every column holds atomic values and there are no repeating groups. "
    "Second normal form (2NF) requires 1NF and that every non-key attribute is fully functionally dependent on the whole primary key, removing partial dependency. "
    "Third normal form (3NF) requires 2NF and removes transitive dependency so non-key attributes depend only on the key. "
    "For example, a student table storing course name with student data can be split into Student and Course tables."
)

WEAK_ANSWER = "Normalization organizes data and reduces redundancy."


def _criteria():
    return [
        {"id": 1, "criterion": "Definition", "description": "Defines normalization correctly.", "max_marks": 2,
         "expected_indicators": ["reduces redundancy", "organizes data"], "sort_order": 1},
        {"id": 2, "criterion": "1NF", "description": "Explains first normal form.", "max_marks": 2,
         "expected_indicators": ["atomic values", "no repeating groups"], "sort_order": 2},
        {"id": 3, "criterion": "2NF", "description": "Explains second normal form.", "max_marks": 2,
         "expected_indicators": ["partial dependency", "fully dependent on primary key"], "sort_order": 3},
        {"id": 4, "criterion": "3NF", "description": "Explains third normal form.", "max_marks": 2,
         "expected_indicators": ["transitive dependency"], "sort_order": 4},
        {"id": 5, "criterion": "Example", "description": "Provides a suitable example.", "max_marks": 2,
         "expected_indicators": ["example table", "split into tables"], "sort_order": 5},
    ]


def _payload(answer=GOOD_ANSWER, **overrides):
    base = {
        "student_answer": {"id": 101, "text": answer, "answer_type": "TEXT"},
        "question": {
            "id": 5,
            "text": QUESTION,
            "total_marks": 10,
            "question_type": "descriptive",
            "difficulty_level": "medium",
            "cognitive_level": "Understand",
        },
        "rubric": {"id": 20, "version": 1, "total_marks": 10, "criteria": _criteria()},
        "learning_outcome": {"code": "CO2", "description": "Explain fundamental database concepts."},
        "course_context": {"course_code": "CSE101", "course_name": "Database Systems"},
    }
    base.update(overrides)
    return base


class FakeHf:
    """Deterministic bag-of-words embeddings so semantic paths are exercised without a model."""

    model_name = "fake/embedding-model"

    def generate_batch_embeddings(self, texts):
        vocab = sorted({w for t in texts for w in t.lower().split()})
        index = {w: i for i, w in enumerate(vocab)}
        vectors = []
        for t in texts:
            vec = [0.0] * len(vocab)
            for w in t.lower().split():
                vec[index[w]] += 1.0
            vectors.append(vec)
        return vectors


class BrokenHf:
    model_name = "broken"

    def generate_batch_embeddings(self, texts):
        raise RuntimeError("model not loaded")


def _criterion_sum(result):
    return round(sum(c["suggested_marks"] for c in result["criterion_results"]), 2)


# ------------------------------------------------------------------ API tests


def test_grade_answer_api_returns_valid_suggestion():
    response = client.post("/api/v1/grade-answer", json=_payload())
    assert response.status_code == 200, response.text
    body = response.json()
    assert body["status"] == "success"
    assert body["maximum_marks"] == 10
    assert 0 <= body["suggested_marks"] <= 10
    assert len(body["criterion_results"]) == 5
    assert _criterion_sum(body) == body["suggested_marks"]
    for c in body["criterion_results"]:
        assert 0 <= c["suggested_marks"] <= c["maximum_marks"]
        assert c["evaluation"]
        assert isinstance(c["evidence"], list)
        assert isinstance(c["missing_elements"], list)
    assert body["overall_feedback"]
    assert body["evaluation_summary"]
    assert "Faculty review" in body["metadata"]["disclaimer"]
    assert "confidence" not in body


def test_grade_answer_strong_answer_scores_higher_than_weak_answer():
    strong = client.post("/api/v1/grade-answer", json=_payload(GOOD_ANSWER)).json()
    weak = client.post("/api/v1/grade-answer", json=_payload(WEAK_ANSWER)).json()
    assert strong["suggested_marks"] > weak["suggested_marks"]
    assert weak["missing_elements"]


def test_grade_answer_missing_answer_rejected():
    payload = _payload()
    del payload["student_answer"]
    assert client.post("/api/v1/grade-answer", json=payload).status_code == 422


def test_grade_answer_blank_answer_rejected():
    response = client.post("/api/v1/grade-answer", json=_payload("   "))
    assert response.status_code == 422
    assert any("empty" in e.lower() for e in response.json()["errors"])


def test_grade_answer_missing_rubric_rejected():
    payload = _payload()
    del payload["rubric"]
    assert client.post("/api/v1/grade-answer", json=payload).status_code == 422


def test_grade_answer_empty_criteria_rejected():
    payload = _payload()
    payload["rubric"]["criteria"] = []
    assert client.post("/api/v1/grade-answer", json=payload).status_code == 422


def test_grade_answer_invalid_criterion_rejected():
    payload = _payload()
    payload["rubric"]["criteria"][0]["max_marks"] = -1
    assert client.post("/api/v1/grade-answer", json=payload).status_code == 422


def test_grade_answer_duplicate_criterion_ids_rejected():
    payload = _payload()
    payload["rubric"]["criteria"][1]["id"] = 1
    response = client.post("/api/v1/grade-answer", json=payload)
    assert response.status_code == 422
    assert any("unique" in e.lower() for e in response.json()["errors"])


def test_grade_answer_rubric_total_mismatch_rejected():
    payload = _payload()
    payload["rubric"]["total_marks"] = 12
    response = client.post("/api/v1/grade-answer", json=payload)
    assert response.status_code == 422


def test_grade_answer_criteria_not_summing_to_rubric_total_rejected():
    payload = _payload()
    payload["rubric"]["criteria"][0]["max_marks"] = 3
    assert client.post("/api/v1/grade-answer", json=payload).status_code == 422


def test_grade_answer_missing_question_marks_rejected():
    payload = _payload()
    del payload["question"]["total_marks"]
    assert client.post("/api/v1/grade-answer", json=payload).status_code == 422


def test_grade_answer_engine_failure_returns_clean_500(monkeypatch):
    def boom(self, request):
        raise RuntimeError("secret /srv/models path and student answer text")

    monkeypatch.setattr(GradingEngine, "grade", boom)
    response = client.post("/api/v1/grade-answer", json=_payload())
    assert response.status_code == 500
    assert "/srv/models" not in response.text
    assert "student answer text" not in response.text


def test_grade_answer_validation_failure_returns_clean_500(monkeypatch):
    def invalid(self, request):
        raise GradingValidationError("Criterion marks (8) do not sum to the suggested total (7).")

    monkeypatch.setattr(GradingEngine, "grade", invalid)
    response = client.post("/api/v1/grade-answer", json=_payload())
    assert response.status_code == 500
    assert "valid grading suggestion" in response.json()["detail"].lower()


def test_grade_answer_timeout_returns_clean_500(monkeypatch):
    def slow(self, request):
        raise TimeoutError("inference timed out")

    monkeypatch.setattr(GradingEngine, "grade", slow)
    assert client.post("/api/v1/grade-answer", json=_payload()).status_code == 500


def test_grade_answer_malformed_engine_output_rejected_by_schema(monkeypatch):
    def malformed(self, request):
        return {"status": "success", "suggested_marks": 7, "maximum_marks": 10, "criterion_results": []}

    monkeypatch.setattr(GradingEngine, "grade", malformed)
    response = client.post("/api/v1/grade-answer", json=_payload())
    assert response.status_code == 500


# ----------------------------------------------------------------- unit tests


def test_engine_with_fake_embeddings_is_deterministic_and_bounded():
    engine = GradingEngine(hf_service=FakeHf(), text_generation_service=None)
    request = GradeAnswerRequest(**_payload())
    first = engine.grade(request)
    second = engine.grade(request)
    assert first == second
    assert first["metadata"]["embedding_model"] == "fake/embedding-model"
    assert first["metadata"]["generation_method"] == "embedding_rubric_alignment"
    assert first["metadata"]["generative_model_used"] is False
    assert _criterion_sum(first) == first["suggested_marks"]
    assert first["suggested_marks"] <= 10


def test_engine_marks_respect_rubric_step_and_bounds():
    engine = GradingEngine(hf_service=FakeHf(), text_generation_service=None)
    result = engine.grade(GradeAnswerRequest(**_payload()))
    for c in result["criterion_results"]:
        assert 0 <= c["suggested_marks"] <= c["maximum_marks"]
        assert (c["suggested_marks"] * 2).is_integer()  # 0.5 step for 2-mark criteria


def test_engine_provides_evidence_and_missing_elements():
    engine = GradingEngine(hf_service=FakeHf(), text_generation_service=None)
    result = engine.grade(GradeAnswerRequest(**_payload()))
    definition = next(c for c in result["criterion_results"] if c["rubric_criterion_id"] == 1)
    assert definition["suggested_marks"] >= 1.5
    assert definition["evidence"]
    assert definition["coverage_level"] in ("STRONG", "PARTIAL")

    weak = engine.grade(GradeAnswerRequest(**_payload(WEAK_ANSWER)))
    third_nf = next(c for c in weak["criterion_results"] if c["rubric_criterion_id"] == 4)
    assert third_nf["suggested_marks"] == 0
    assert "transitive dependency" in third_nf["missing_elements"]
    assert third_nf["coverage_level"] == "NOT_ADDRESSED"
    assert any(m.startswith("3NF:") for m in weak["missing_elements"])


def test_engine_very_short_answer_is_capped():
    engine = GradingEngine(hf_service=FakeHf(), text_generation_service=None)
    result = engine.grade(GradeAnswerRequest(**_payload("Normalization.")))
    assert result["suggested_marks"] <= 2.5
    assert "very short" in result["evaluation_summary"]


def test_engine_falls_back_to_lexical_when_embeddings_fail():
    engine = GradingEngine(hf_service=BrokenHf(), text_generation_service=None)
    result = engine.grade(GradeAnswerRequest(**_payload()))
    assert result["metadata"]["embedding_model"] is None
    assert result["metadata"]["generation_method"] == "lexical_rubric_alignment"
    assert "keyword alignment" in result["evaluation_summary"]
    assert _criterion_sum(result) == result["suggested_marks"]


def test_engine_uses_generative_refinement_when_available():
    class FakeGen:
        is_configured = True
        model_name = "fake/flan-t5-small"

        def generate_lines(self, prompt, max_new_tokens=None):
            assert "Feedback:" in prompt
            return ["The definition is accurate but the purpose of organizing data could be expanded."]

    engine = GradingEngine(hf_service=FakeHf(), text_generation_service=FakeGen())
    result = engine.grade(GradeAnswerRequest(**_payload()))
    assert result["metadata"]["generative_model_used"] is True
    assert result["metadata"]["generation_method"] == "ai_assisted"
    assert result["metadata"]["model"] == "fake/flan-t5-small"
    assert "could be expanded" in result["criterion_results"][0]["evaluation"]


def test_engine_ignores_malformed_generative_output():
    class BrokenGen:
        is_configured = True
        model_name = "fake/flan-t5-small"

        def generate_lines(self, prompt, max_new_tokens=None):
            return ["", "!!!", "x"]

    engine = GradingEngine(hf_service=FakeHf(), text_generation_service=BrokenGen())
    result = engine.grade(GradeAnswerRequest(**_payload()))
    assert result["metadata"]["generative_model_used"] is False
    assert result["metadata"]["model"] == "facultylens-grading-engine"


def test_engine_survives_generative_failure():
    class TimeoutGen:
        is_configured = True
        model_name = "fake/flan-t5-small"

        def generate_lines(self, prompt, max_new_tokens=None):
            raise TimeoutError("inference timed out")

    engine = GradingEngine(hf_service=FakeHf(), text_generation_service=TimeoutGen())
    result = engine.grade(GradeAnswerRequest(**_payload()))
    assert result["metadata"]["generative_model_used"] is False


# ------------------------------------------------------------ validator tests


def _valid_result():
    return {
        "suggested_marks": 7.5,
        "maximum_marks": 10,
        "criterion_results": [
            {"rubric_criterion_id": 1, "suggested_marks": 2, "maximum_marks": 2, "evaluation": "ok", "evidence": [], "missing_elements": []},
            {"rubric_criterion_id": 2, "suggested_marks": 2, "maximum_marks": 2, "evaluation": "ok", "evidence": [], "missing_elements": []},
            {"rubric_criterion_id": 3, "suggested_marks": 1, "maximum_marks": 2, "evaluation": "ok", "evidence": [], "missing_elements": []},
            {"rubric_criterion_id": 4, "suggested_marks": 1, "maximum_marks": 2, "evaluation": "ok", "evidence": [], "missing_elements": []},
            {"rubric_criterion_id": 5, "suggested_marks": 1.5, "maximum_marks": 2, "evaluation": "ok", "evidence": [], "missing_elements": []},
        ],
        "overall_feedback": "Reasonable.",
        "evaluation_summary": "Summary.",
    }


def test_validator_accepts_consistent_result():
    ok, errors = GradingValidator.validate(_valid_result(), _criteria(), 10)
    assert ok, errors


def test_validator_rejects_criterion_total_mismatch():
    result = _valid_result()
    result["suggested_marks"] = 7
    ok, errors = GradingValidator.validate(result, _criteria(), 10)
    assert not ok
    assert any("do not sum" in e for e in errors)


def test_validator_rejects_marks_above_criterion_maximum():
    result = _valid_result()
    result["criterion_results"][0]["suggested_marks"] = 3
    result["suggested_marks"] = 8.5
    ok, errors = GradingValidator.validate(result, _criteria(), 10)
    assert not ok
    assert any("exceed its maximum" in e for e in errors)


def test_validator_rejects_negative_and_over_total_marks():
    result = _valid_result()
    result["criterion_results"][0]["suggested_marks"] = -1
    result["suggested_marks"] = 4.5
    ok, errors = GradingValidator.validate(result, _criteria(), 10)
    assert not ok
    assert any("negative" in e for e in errors)

    result = _valid_result()
    result["suggested_marks"] = 11
    ok, errors = GradingValidator.validate(result, _criteria(), 10)
    assert not ok


def test_validator_rejects_unknown_or_missing_criteria():
    result = _valid_result()
    result["criterion_results"][0]["rubric_criterion_id"] = 99
    ok, errors = GradingValidator.validate(result, _criteria(), 10)
    assert not ok
    assert any("unknown rubric criterion" in e for e in errors)
    assert any("missing for rubric criteria" in e for e in errors)


def test_validator_assert_valid_raises():
    result = _valid_result()
    result["criterion_results"].pop()
    with pytest.raises(GradingValidationError):
        GradingValidator.assert_valid(result, _criteria(), 10)
