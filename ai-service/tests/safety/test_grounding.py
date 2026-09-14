"""STEP 46 — RAG grounding and citation verification.

Every grounded answer must be supported by the authorized chunks it was given; every [S#]
citation must point at a real context block; page/section metadata always comes from the chunk,
never from the model. Cross-user / cross-course retrieval isolation is a Laravel responsibility
and is covered by backend/tests/Feature/Safety.
"""

import pytest

from app.schemas.chat import AcademicChatRequest
from app.services import safety
from app.services.academic_chat import AcademicChatService
from evaluation import safety_harness as harness
from tests.safety.conftest import cases_in, ids_for, run

GROUNDING_CASES = cases_in("GROUNDING")
CITATION_CASES = cases_in("CITATION")


@pytest.mark.parametrize("case", GROUNDING_CASES, ids=ids_for(GROUNDING_CASES))
def test_grounding_case(case):
    run(case)


@pytest.mark.parametrize("case", CITATION_CASES, ids=ids_for(CITATION_CASES))
def test_citation_case(case):
    run(case)


# ------------------------------------------------------------------ citation validator


@pytest.mark.parametrize("text,size,expected", [
    ("Answer [S1].", 2, {"cited": [1], "valid": [1], "invalid": [], "fabricated": False}),
    ("Answer [S1] and [S2].", 2, {"cited": [1, 2], "valid": [1, 2], "invalid": [], "fabricated": False}),
    ("Answer [S7].", 2, {"cited": [7], "valid": [], "invalid": [7], "fabricated": True}),
    ("Answer [S1] and [S9].", 2, {"cited": [1, 9], "valid": [1], "invalid": [9], "fabricated": False}),
    ("Answer without citation.", 2, {"cited": [], "valid": [], "invalid": [], "fabricated": False}),
    ("Answer [S0].", 2, {"cited": [0], "valid": [], "invalid": [0], "fabricated": True}),
])
def test_validate_citations(text, size, expected):
    assert safety.validate_citations(text, size) == expected


def test_partially_fabricated_citations_only_keep_the_real_ones():
    gen = harness.FakeGeneration("Outcomes cover SQL [S1] and quantum gates [S9].")
    req = AcademicChatRequest(question="What are the learning outcomes?", chunks=[
        {"chunk_id": 21, "document_id": 4, "document_name": "Syllabus.pdf", "content": "CO1: Write SQL queries.", "similarity_score": 0.86},
        {"chunk_id": 34, "document_id": 5, "document_name": "Notes.docx", "content": "Normalization reduces redundancy.", "similarity_score": 0.71},
    ])
    result = AcademicChatService(generation_service=gen).answer(req)
    assert [s["chunk_id"] for s in result["sources"]] == [21]
    assert result["safety"]["citation_validation"] == "passed"


def test_sources_are_always_a_subset_of_the_authorized_chunks():
    """No path may emit a source that was not in the request (Laravel additionally re-validates ids)."""
    chunks = [
        {"chunk_id": 21, "document_id": 4, "document_name": "Syllabus.pdf", "content": "CO1: Write SQL queries.", "similarity_score": 0.86},
        {"chunk_id": 34, "document_id": 5, "document_name": "Notes.docx", "content": "Normalization reduces redundancy.", "similarity_score": 0.71},
    ]
    allowed = {21, 34}
    for gen_text in [None, "Answer [S1].", "Answer [S2].", "Answer [S1][S2].", "Answer [S5].", "No citations here."]:
        gen = harness.FakeGeneration(gen_text) if gen_text else None
        result = AcademicChatService(generation_service=gen).answer(AcademicChatRequest(question="What does the document say about normalization or SQL queries?", chunks=chunks))
        assert {s["chunk_id"] for s in result["sources"]} <= allowed, gen_text
        assert result["used_count"] == len(result["sources"])


def test_grounded_flag_requires_sources():
    """grounded=True with an empty source list would be an unsupported claim."""
    all_cases = harness.load_cases()
    for case in [c for c in all_cases if c["surface"] == "chat"]:
        result = harness.execute(case)
        if result.status_code == 200 and result.body.get("grounded"):
            assert result.body["sources"], f"{case['id']} grounded without sources"
        if result.status_code == 200 and not result.body.get("grounded"):
            assert result.body["sources"] == [], f"{case['id']} ungrounded with sources"
