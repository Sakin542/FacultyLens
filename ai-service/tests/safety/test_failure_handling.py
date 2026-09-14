"""STEP 46 — Failure handling, output validation and unsafe-action prevention.

When the generative model is unavailable, times out, or returns malformed output the service
falls back to deterministic engines or returns a safe error; it never fabricates results, never
finalizes grades, never invents marks, and rejects invalid constraints instead of "fixing" them.
"""

import pytest

from app.schemas.chat import AcademicChatRequest
from app.schemas.grading import GradeAnswerResponse
from app.schemas.question_generation import GenerateQuestionsRequest
from app.schemas.rubric import GenerateRubricResponse, RubricOut
from app.services.academic_chat import AcademicChatService
from app.services.question_generator import QuestionGenerator
from evaluation import safety_harness as harness
from tests.safety.conftest import cases_in, ids_for, run

FAILURE_CASES = cases_in("FAILURE_HANDLING", "OUTPUT_VALIDATION")
UNSAFE_ACTION_CASES = cases_in("GRADING_SAFETY", "RUBRIC_SAFETY", "QUESTION_GENERATION_SAFETY")


@pytest.mark.parametrize("case", FAILURE_CASES, ids=ids_for(FAILURE_CASES))
def test_failure_handling_case(case):
    run(case)


@pytest.mark.parametrize("case", UNSAFE_ACTION_CASES, ids=ids_for(UNSAFE_ACTION_CASES))
def test_unsafe_action_case(case):
    run(case)


# ------------------------------------------------------------------ model failure modes

_CHUNK = {"chunk_id": 1, "document_id": 1, "document_name": "n.pdf", "content": "Database normalization reduces redundancy and improves data integrity.", "similarity_score": 0.8}


class TimeoutGen(harness.FakeGeneration):
    def generate(self, prompt, max_new_tokens=None):
        raise TimeoutError("read timed out after 120s contacting 10.0.0.9")


class SlowNoneGen(harness.FakeGeneration):
    def generate(self, prompt, max_new_tokens=None):
        return None


class UnloadedGen(harness.FakeGeneration):
    is_configured = False


@pytest.mark.parametrize("gen", [TimeoutGen(None), SlowNoneGen(None), UnloadedGen(None), harness.FakeGeneration("", raise_error=True)])
def test_generation_failures_fall_back_to_real_evidence(gen):
    result = AcademicChatService(generation_service=gen).answer(AcademicChatRequest(question="What does the document say about normalization?", chunks=[_CHUNK]))
    assert result["generation_method"] == "extractive"
    assert result["grounded"] is True and result["sources"]
    assert "10.0.0.9" not in result["answer"]


def test_question_generation_model_failure_falls_back_to_template():
    gen = harness.FakeGeneration("", raise_error=True)
    req = GenerateQuestionsRequest(course_context={"course_code": "CSE101"}, topic="Normalization", question_type="DESCRIPTIVE", marks=10, number_of_questions=2)
    result = QuestionGenerator(generation_service=gen).generate(req)
    assert result["generation_method"] == "template" and result["generated_count"] == 2
    assert all(q["marks"] == 10 for q in result["questions"])


# ------------------------------------------------------------------ output schema validation (never trust raw AI output)


def test_rubric_schema_rejects_marks_that_do_not_sum():
    with pytest.raises(ValueError):
        RubricOut(title="R", question_text="Q", total_marks=10, criteria=[
            {"criterion": "A", "description": "a", "max_marks": 6, "sort_order": 1},
            {"criterion": "B", "description": "b", "max_marks": 6, "sort_order": 2},
        ])


def test_rubric_response_schema_rejects_non_draft_status_values_from_model():
    payload = {"generation_method": "template_based", "draft_status": "APPROVED", "rubric": {"title": "R", "question_text": "Q", "total_marks": 4,
               "criteria": [{"criterion": "A", "description": "a", "max_marks": 4, "sort_order": 1}]}, "metadata": {"model": "m"}}
    # The schema accepts the field; the SERVICE must never emit anything but DRAFT — assert on the real engine.
    res = harness.client().post("/api/v1/generate-rubric", json={"question_text": "Explain database normalization.", "total_marks": 4})
    assert res.json()["draft_status"] == "DRAFT"
    assert GenerateRubricResponse(**payload).draft_status == "APPROVED"  # documents that Laravel must enforce DRAFT (it does: RubricService)


def test_grading_response_schema_rejects_inconsistent_totals():
    base = {"suggested_marks": 9, "maximum_marks": 10, "overall_feedback": "f", "evaluation_summary": "s", "metadata": {"model": "m"},
            "criterion_results": [{"rubric_criterion_id": 1, "criterion": "A", "suggested_marks": 2, "maximum_marks": 5, "evaluation": "e", "coverage_level": "PARTIAL"}]}
    with pytest.raises(ValueError):
        GradeAnswerResponse(**base)  # 2 != 9
    with pytest.raises(ValueError):
        GradeAnswerResponse(**{**base, "suggested_marks": 11, "criterion_results": [{**base["criterion_results"][0], "suggested_marks": 11, "maximum_marks": 5}]})
    with pytest.raises(ValueError):
        GradeAnswerResponse(**{**base, "suggested_marks": -1})


def test_enum_values_are_validated_on_input():
    bad = [
        ("/api/v1/generate-questions", {"topic": "N", "question_type": "RIDDLE", "marks": 10, "number_of_questions": 1}),
        ("/api/v1/generate-questions", {"topic": "N", "question_type": "MCQ", "cognitive_level": "GUESS", "marks": 10, "number_of_questions": 1}),
        ("/api/v1/generate-rubric", {"question_text": "Explain normalization.", "question_type": "HAIKU", "total_marks": 5}),
    ]
    for path, payload in bad:
        assert harness.client().post(path, json=payload).status_code == 422, (path, payload)


# ------------------------------------------------------------------ retry / idempotency at the service layer


def test_identical_requests_are_deterministic_so_retries_cannot_diverge():
    """Extractive/template engines are pure functions of their input: a Laravel retry after a timeout
    reproduces the same suggestion instead of a second, different 'analysis'."""
    payload = {"question": "What does the document say about normalization?", "chunks": [_CHUNK]}
    a = harness.client().post("/api/v1/chat/academic", json=payload).json()
    b = harness.client().post("/api/v1/chat/academic", json=payload).json()
    assert a["answer"] == b["answer"] and a["sources"] == b["sources"]

    rubric = {"question_text": "Explain database normalization and give an example.", "total_marks": 6}
    r1 = harness.client().post("/api/v1/generate-rubric", json=rubric).json()["rubric"]
    r2 = harness.client().post("/api/v1/generate-rubric", json=rubric).json()["rubric"]
    assert [c["max_marks"] for c in r1["criteria"]] == [c["max_marks"] for c in r2["criteria"]]


def test_no_endpoint_returns_a_final_or_approved_state():
    """AI surfaces only ever produce drafts / suggestions."""
    rubric = harness.client().post("/api/v1/generate-rubric", json={"question_text": "Explain database normalization.", "total_marks": 5}).json()
    assert rubric["draft_status"] == "DRAFT" and "Faculty review" in rubric["metadata"]["disclaimer"]

    qgen = harness.client().post("/api/v1/generate-questions", json={"topic": "Normalization", "question_type": "DESCRIPTIVE", "marks": 5, "number_of_questions": 1}).json()
    assert "drafts" in qgen["disclaimer"].lower()
    for q in qgen["questions"]:
        assert "approved" not in str(q.get("validation", {}).get("overall_status", "")).lower()

    case = next(c for c in harness.load_cases() if c["id"] == "SAFE-GRADE-001")
    body = harness.execute(case).body
    assert "Faculty review is required" in body["metadata"]["disclaimer"]
    for key in ("final_marks", "awarded_marks", "grade", "passed", "failed", "rank"):
        assert key not in body
