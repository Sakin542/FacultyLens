"""Document chat: every claim of a generated answer must be backed by one passage of the documents.

Found in browser testing: asked "How does linear probing resolve collisions?", the small local model answered that it
"maintains a list of free slots in memory" — nowhere in the syllabus — and the answer was labelled grounded.
"""

from app.schemas.chat import AcademicChatRequest
from app.services.academic_chat import AcademicChatService

SYLLABUS = """CSE 3201 Data Structures and Algorithms — Course Syllabus
Course outcomes: CO1 apply graph traversal algorithms; CO2 analyze hashing and collision resolution.
Week 5-6: Graphs. Breadth-first search (BFS) explores a graph level by level using a queue and finds the shortest path in an unweighted graph.
Week 7-8: Hashing. A hash table maps keys to slots with a hash function. Collisions are resolved by separate chaining or open addressing such as linear probing. Linear probing causes primary clustering.
Week 9-10: Sorting: merge sort, quick sort and heap sort."""

INVENTED = ("Linear probing resolves collisions by maintaining a list of free slots in memory. When a collision occurs, the "
            "algorithm searches through the list until it finds a slot that has not been occupied yet.")
FAITHFUL = "Collisions are resolved by open addressing such as linear probing [S1]. Linear probing causes primary clustering [S1]."


class FakeGen:
    is_configured = True
    model_name = "fake/qwen"

    def __init__(self, text):
        self.text = text

    def generate(self, prompt, max_new_tokens=None):
        return self.text


def _ask(answer, question="How does linear probing resolve collisions?"):
    req = AcademicChatRequest(question=question, chunks=[{"chunk_id": 1, "document_id": 1, "document_name": "cse3201-syllabus.txt", "content": SYLLABUS, "similarity_score": 0.6}])
    return AcademicChatService(generation_service=FakeGen(answer)).answer(req)


def test_invented_answer_is_replaced_by_quotes_from_the_documents():
    result = _ask(INVENTED)
    assert result["generation_method"] == "extractive"
    assert "list of free slots" not in result["answer"]
    assert "linear probing" in result["answer"].lower() and "[S1]" in result["answer"]
    assert "Sorting" not in result["answer"], "an unrelated line of the same document is left out"


def test_faithful_answer_is_kept():
    result = _ask(FAITHFUL)
    assert result["generation_method"] == "generative" and result["answer"] == FAITHFUL


def test_claims_are_judged_against_one_passage_not_scattered_words():
    ctx = [{"content": SYLLABUS}]
    assert AcademicChatService.unsupported_claims(INVENTED, ctx)
    assert AcademicChatService.unsupported_claims(FAITHFUL, ctx) == []
    # A summary of a list spans its lines and is fine
    assert AcademicChatService.unsupported_claims("The course outcomes are graph traversal algorithms and hashing collision resolution.", ctx) == []


def test_questions_without_a_subject_of_their_own_are_not_checked():
    assert AcademicChatService.has_specific_subject("How does linear probing resolve collisions?")
    assert not AcademicChatService.has_specific_subject("What does this document say?")
    assert not AcademicChatService.has_specific_subject("Summarize the key points of the notes")
    # so a descriptive summary of the document is not rejected
    result = _ask("The syllabus describes a data structures course with graph and hashing units.", question="What does this document say?")
    assert result["generation_method"] == "generative"
