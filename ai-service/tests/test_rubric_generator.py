"""Tests for STEP 25 AI Rubric Generator (FastAPI)."""

import pytest
from fastapi.testclient import TestClient

from app.main import app
from app.schemas.rubric import GenerateRubricRequest, RubricQuestionType
from app.services.rubric_generator import RubricGenerator
from app.services.rubric_validator import RubricValidationError, RubricValidator

client = TestClient(app)

NORMALIZATION_QUESTION = (
    "Explain the process of database normalization and describe 1NF, 2NF, and 3NF with examples."
)


def _payload(**overrides):
    base = {
        "question_id": 15,
        "question_text": NORMALIZATION_QUESTION,
        "question_type": "DESCRIPTIVE",
        "total_marks": 10,
        "difficulty_level": "MEDIUM",
        "cognitive_level": "UNDERSTAND",
        "learning_outcome": {"code": "CO2", "description": "Explain fundamental database concepts."},
        "course_context": {"course_code": "CSE101", "course_name": "Database Systems"},
    }
    base.update(overrides)
    return base


def _criteria_sum(rubric):
    return round(sum(c["max_marks"] for c in rubric["criteria"]), 2)


# ------------------------------------------------------------------ API tests


def test_generate_rubric_api_returns_valid_draft():
    response = client.post("/api/v1/generate-rubric", json=_payload())
    assert response.status_code == 200
    data = response.json()

    assert data["status"] == "success"
    assert data["draft_status"] == "DRAFT"
    assert data["generation_method"] in ("ai_assisted", "template_based")

    rubric = data["rubric"]
    assert rubric["total_marks"] == 10
    assert len(rubric["criteria"]) >= 2
    assert _criteria_sum(rubric) == 10

    for idx, criterion in enumerate(rubric["criteria"], start=1):
        assert criterion["criterion"]
        assert criterion["description"]
        assert criterion["max_marks"] >= 0
        assert criterion["sort_order"] == idx
        assert isinstance(criterion["expected_indicators"], list)

    metadata = data["metadata"]
    assert metadata["model"]
    assert metadata["validation_passed"] is True
    assert metadata["criteria_count"] == len(rubric["criteria"])
    assert "confidence" not in metadata
    assert "review" in metadata["disclaimer"].lower()


def test_generate_rubric_extracts_enumerated_components():
    response = client.post("/api/v1/generate-rubric", json=_payload())
    names = " | ".join(c["criterion"].lower() for c in response.json()["rubric"]["criteria"])
    assert "1nf" in names
    assert "2nf" in names
    assert "3nf" in names
    assert "example" in names


def test_generate_rubric_missing_question_text_rejected():
    payload = _payload()
    payload.pop("question_text")
    response = client.post("/api/v1/generate-rubric", json=payload)
    assert response.status_code == 422
    assert response.json()["status"] == "error"


def test_generate_rubric_blank_question_text_rejected():
    response = client.post("/api/v1/generate-rubric", json=_payload(question_text="   "))
    assert response.status_code == 422


def test_generate_rubric_missing_marks_rejected():
    payload = _payload()
    payload.pop("total_marks")
    response = client.post("/api/v1/generate-rubric", json=payload)
    assert response.status_code == 422


def test_generate_rubric_zero_marks_rejected():
    response = client.post("/api/v1/generate-rubric", json=_payload(total_marks=0))
    assert response.status_code == 422


def test_generate_rubric_unsupported_question_type_rejected():
    response = client.post("/api/v1/generate-rubric", json=_payload(question_type="ORAL_EXAM"))
    assert response.status_code == 422
    assert any("Unsupported question type" in e for e in response.json()["errors"])


def test_generate_rubric_accepts_lowercase_laravel_question_types():
    for q_type in ("mcq", "short_answer", "descriptive", "problem_solving", "true_false", "other"):
        response = client.post("/api/v1/generate-rubric", json=_payload(question_type=q_type, total_marks=5))
        assert response.status_code == 200, q_type
        assert _criteria_sum(response.json()["rubric"]) == 5


@pytest.mark.parametrize(
    "q_type,text,marks",
    [
        ("MCQ", "Which of the following is a valid primary key? a) NULL b) Duplicate c) Unique non-null d) Any", 1),
        ("TRUE_FALSE", "State whether true or false: A foreign key must be unique.", 2),
        ("SHORT_ANSWER", "Briefly explain the purpose of an index in a database.", 3),
        ("PROBLEM_SOLVING", "Calculate the time complexity of binary search and derive the recurrence relation.", 7),
        ("CONCEPTUAL", "Define ACID properties and give an example of each.", 8),
        ("ANALYTICAL", "Compare B-tree and hash indexes and justify which is better for range queries.", 12.5),
    ],
)
def test_generate_rubric_supports_all_question_types(q_type, text, marks):
    response = client.post(
        "/api/v1/generate-rubric",
        json=_payload(question_type=q_type, question_text=text, total_marks=marks),
    )
    assert response.status_code == 200, response.text
    rubric = response.json()["rubric"]
    assert _criteria_sum(rubric) == marks
    assert all(c["max_marks"] >= 0 for c in rubric["criteria"])


def test_generate_rubric_single_mark_yields_single_criterion():
    response = client.post(
        "/api/v1/generate-rubric",
        json=_payload(question_type="MCQ", question_text="Which of the following is true? a) x b) y", total_marks=1),
    )
    rubric = response.json()["rubric"]
    assert len(rubric["criteria"]) == 1
    assert rubric["criteria"][0]["max_marks"] == 1


def test_generate_rubric_service_failure_returns_clean_500(monkeypatch):
    def boom(self, request):
        raise RuntimeError("model exploded with secret path /srv/models")

    monkeypatch.setattr(RubricGenerator, "generate", boom)
    response = client.post("/api/v1/generate-rubric", json=_payload())
    assert response.status_code == 500
    body = response.json()
    assert "/srv/models" not in str(body)


def test_generate_rubric_validation_failure_returns_clean_500(monkeypatch):
    def invalid(self, request):
        raise RubricValidationError("Criterion marks sum to 11 but the question total is 10.")

    monkeypatch.setattr(RubricGenerator, "generate", invalid)
    response = client.post("/api/v1/generate-rubric", json=_payload())
    assert response.status_code == 500
    assert "valid rubric" in response.json()["detail"].lower()


# -------------------------------------------------------------- unit tests


def test_extract_tasks_finds_verbs_and_enumerations():
    tasks = RubricGenerator.extract_tasks(NORMALIZATION_QUESTION)
    verbs = [t["verb"] for t in tasks]
    assert "explain" in verbs
    assert "describe" in verbs
    describe_task = next(t for t in tasks if t["verb"] == "describe")
    assert describe_task["items"] == ["1NF", "2NF", "3NF"]


@pytest.mark.parametrize("total", [1, 2, 3, 5, 7, 10, 12.5, 15, 20, 0.5, 2.25])
def test_distribute_marks_sums_exactly(total):
    weights = [2.0, 2.0, 2.0, 1.5, 1.0]
    marks = RubricGenerator._distribute_marks(weights, total)
    assert round(sum(marks), 2) == round(total, 2)
    assert all(m >= 0 for m in marks)


def test_distribute_marks_handles_weights_larger_than_units():
    marks = RubricGenerator._distribute_marks([2.0, 2.0, 2.0], 1)
    assert round(sum(marks), 2) == 1


def test_generator_without_services_is_deterministic():
    generator = RubricGenerator(hf_service=None, text_generation_service=None)
    request = GenerateRubricRequest(**_payload())
    first = generator.generate(request)
    second = generator.generate(request)
    assert first["rubric"] == second["rubric"]
    assert first["generation_method"] == "template_based"
    assert first["metadata"]["generative_model_used"] is False
    assert first["metadata"]["model"] == "facultylens-rubric-template-engine"


def test_generator_uses_generative_candidates_when_available():
    class FakeGen:
        is_configured = True
        model_name = "fake/flan-t5-small"

        def generate_lines(self, prompt, max_new_tokens=None):
            return ["1. Definition of normalization", "2. Explanation of 1NF", "- Examples provided", "Clarity"]

    generator = RubricGenerator(hf_service=None, text_generation_service=FakeGen())
    result = generator.generate(GenerateRubricRequest(**_payload()))
    assert result["generation_method"] == "ai_assisted"
    assert result["metadata"]["model"] == "fake/flan-t5-small"
    assert result["metadata"]["generative_model_used"] is True
    names = [c["criterion"] for c in result["rubric"]["criteria"]]
    assert "Definition of normalization" in names
    assert _criteria_sum(result["rubric"]) == 10


def test_generator_falls_back_when_generative_output_malformed():
    class BrokenGen:
        is_configured = True
        model_name = "fake/flan-t5-small"

        def generate_lines(self, prompt, max_new_tokens=None):
            return ["", "!!!", "x"]

    generator = RubricGenerator(hf_service=None, text_generation_service=BrokenGen())
    result = generator.generate(GenerateRubricRequest(**_payload()))
    assert result["generation_method"] == "template_based"
    assert result["metadata"]["generative_model_used"] is False
    assert _criteria_sum(result["rubric"]) == 10


def test_generator_survives_generative_timeout():
    class TimeoutGen:
        is_configured = True
        model_name = "fake/flan-t5-small"

        def generate_lines(self, prompt, max_new_tokens=None):
            raise TimeoutError("inference timed out")

    generator = RubricGenerator(hf_service=None, text_generation_service=TimeoutGen())
    with pytest.raises(TimeoutError):
        generator.generate(GenerateRubricRequest(**_payload()))


def test_generator_survives_embedding_failure():
    class BrokenHf:
        model_name = "broken"

        def generate_batch_embeddings(self, texts):
            raise RuntimeError("model not loaded")

    generator = RubricGenerator(hf_service=BrokenHf(), text_generation_service=None)
    result = generator.generate(GenerateRubricRequest(**_payload()))
    assert result["metadata"]["embedding_model"] is None
    assert _criteria_sum(result["rubric"]) == 10


# ---------------------------------------------------------- validator tests


def _valid_rubric():
    return {
        "title": "Rubric",
        "total_marks": 10,
        "criteria": [
            {"criterion": "A", "description": "a", "max_marks": 4, "sort_order": 1, "expected_indicators": []},
            {"criterion": "B", "description": "b", "max_marks": 6, "sort_order": 2, "expected_indicators": ["x"]},
        ],
    }


def test_validator_accepts_valid_rubric():
    ok, errors = RubricValidator.validate(_valid_rubric(), 10)
    assert ok and errors == []


def test_validator_rejects_marks_mismatch():
    rubric = _valid_rubric()
    rubric["criteria"][1]["max_marks"] = 7
    ok, errors = RubricValidator.validate(rubric, 10)
    assert not ok
    assert any("sum to 11" in e for e in errors)


def test_validator_rejects_negative_marks():
    rubric = _valid_rubric()
    rubric["criteria"][0]["max_marks"] = -1
    rubric["criteria"][1]["max_marks"] = 11
    ok, errors = RubricValidator.validate(rubric, 10)
    assert not ok
    assert any("negative" in e for e in errors)


def test_validator_rejects_empty_criteria():
    rubric = _valid_rubric()
    rubric["criteria"] = []
    ok, errors = RubricValidator.validate(rubric, 10)
    assert not ok


def test_validator_rejects_missing_fields_and_bad_types():
    rubric = _valid_rubric()
    rubric["criteria"][0] = {"criterion": "", "max_marks": "four", "sort_order": 1}
    ok, errors = RubricValidator.validate(rubric, 10)
    assert not ok
    assert any("description" in e for e in errors)
    assert any("invalid marks" in e for e in errors)


def test_validator_rejects_duplicate_or_invalid_sort_order():
    rubric = _valid_rubric()
    rubric["criteria"][1]["sort_order"] = 1
    ok, errors = RubricValidator.validate(rubric, 10)
    assert not ok
    assert any("duplicated" in e for e in errors)


def test_validator_rejects_total_mismatch_with_question():
    ok, errors = RubricValidator.validate(_valid_rubric(), 12)
    assert not ok
    assert any("does not match the question total" in e for e in errors)


def test_validator_assert_raises():
    rubric = _valid_rubric()
    rubric["criteria"][0]["max_marks"] = 5
    with pytest.raises(RubricValidationError):
        RubricValidator.assert_valid(rubric, 10)


def test_schema_rejects_inconsistent_rubric_out():
    from pydantic import ValidationError
    from app.schemas.rubric import RubricOut

    with pytest.raises(ValidationError):
        RubricOut(
            title="t",
            question_text="q",
            total_marks=10,
            criteria=[
                {"criterion": "A", "description": "a", "max_marks": 4, "sort_order": 1},
                {"criterion": "B", "description": "b", "max_marks": 7, "sort_order": 2},
            ],
        )


def test_question_type_enum_matches_project_types():
    expected = {"MCQ", "SHORT_ANSWER", "DESCRIPTIVE", "PROBLEM_SOLVING", "TRUE_FALSE", "CONCEPTUAL", "ANALYTICAL", "OTHER"}
    assert set(RubricQuestionType.__members__.keys()) == expected
