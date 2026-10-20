"""Study pack grounding, question checks, simplify and Bloom rewrite — with a fake generator (no model download)."""

import pytest
from fastapi.testclient import TestClient

from app.main import app
from app.services import writing_tools as wt
from app.services.generation_service import get_generation_service

client = TestClient(app)

LECTURE = (
    "Today we are going to talk about graph traversal. A graph is a set of vertices connected by edges. "
    "Breadth-first search explores a graph level by level. It starts at a source vertex and visits all of its neighbours "
    "before moving to the next level, and it uses a queue to remember which vertex to visit next. "
    "Because it visits vertices in order of distance, breadth-first search finds the shortest path in an unweighted graph. "
    "Depth-first search works differently. It goes as deep as possible along one branch before backtracking, and it uses a stack, "
    "or recursion, to keep track of the path. Depth-first search is useful for detecting cycles and for topological sorting. "
    "Both algorithms run in time proportional to the number of vertices plus the number of edges."
)


class FakeGen:
    """Stands in for GenerationService: returns scripted replies in order."""

    is_configured = True
    model_name = "fake/model"

    def __init__(self, *replies):
        self.replies = list(replies)
        self.prompts = []

    def generate(self, prompt, max_new_tokens=None, completion=False):
        self.prompts.append((prompt, completion))
        return self.replies.pop(0) if self.replies else None


SENTENCES = wt.split_sentences(LECTURE)
builder = wt.StudyPackBuilder(None, None)


# ------------------------------------------------------------------------------------------- grounding

@pytest.mark.parametrize("q,a", [
    ("What data structure does breadth-first search use?", "A queue."),
    ("How does depth-first search explore a graph?", "It goes as deep as possible along one branch before backtracking."),
    ("What is depth-first search useful for?", "Detecting cycles and topological sorting."),
    ("Which search finds the shortest path in an unweighted graph?", "Breadth-first search."),
])
def test_correct_answers_are_grounded_with_evidence(q, a):
    assert builder.support(q, a, SENTENCES) is not None


@pytest.mark.parametrize("q,a", [
    # The exact kinds of mistakes the 0.5B model made in live testing: swapped concepts and invented definitions
    ("What data structure does breadth-first search use?", "A stack."),
    ("How does depth-first search explore a graph?", "Depth-first search explores nodes level by level."),
    ("What is breadth-first search useful for?", "Detecting cycles and topological sorting."),
    ("What is a cycle in a graph?", "A loop where each edge points back to itself."),
    ("What is topological sorting?", "Arranging vertices so no two connected vertices have a higher degree than their incoming edges."),
])
def test_swapped_or_invented_answers_are_rejected(q, a):
    assert builder.support(q, a, SENTENCES) is None


def test_multi_sentence_answer_is_checked_claim_by_claim():
    good = ("Breadth-first search starts at a source vertex and explores all of its neighbours before moving to the next level. "
            "Depth-first search goes as deep as possible along one branch before backtracking.")
    bad = ("Breadth-first search starts at a source vertex and explores all of its neighbours before moving to the next level. "
           "Depth-first search explores the graph level by level using a queue.")
    q = "What is the difference between breadth-first search and depth-first search?"
    assert builder.support(q, good, SENTENCES) is not None
    assert builder.support(q, bad, SENTENCES) is None


def test_quiz_parses_both_formats_drops_unsupported_pairs_and_invented_material():
    raw = (
        "What data structure does breadth-first search use?\nA: A queue.\n"
        "Q: How does it work?\nA: It explores.\n"                                   # pronoun-only subject
        "Q: How does depth-first search explore a graph? | A: Level by level.\n"    # swapped fact
        "Q: What is depth-first search useful for?\nA: Detecting cycles and topological sorting.\n"
        "\nMaterial: The table shows tuition costs.\nQ: What is the tuition for class A?\nA: $400\n"
    )
    gen = FakeGen(raw)
    quiz, rejected = wt.StudyPackBuilder(gen, None)._quiz("ctx", SENTENCES, [], 4)
    assert [q["answer"] for q in quiz] == ["A queue.", "Detecting cycles and topological sorting."]
    assert all(q["evidence"] for q in quiz)
    assert rejected == 2
    assert gen.prompts[0][1] is True, "few-shot quiz prompt must use plain completion mode"
    assert gen.prompts[0][0].endswith("Q:")


def test_study_pack_tops_up_with_grounded_fill_in_the_blank_and_reports_methods():
    gen = FakeGen("Graph traversal visits every vertex. BFS goes level by level using a queue; DFS goes deep using a stack.", "nothing parseable")
    pack = wt.StudyPackBuilder(gen, None).build(LECTURE, "Graphs", 3)
    assert pack["summary_method"] == "generative"
    assert pack["quiz_method"] == "template"
    assert len(pack["quiz"]) == 3
    assert all(q["type"] == "fill_in_blank" and q["answer"].lower() in q["evidence"].lower() for q in pack["quiz"])
    answers = [q["answer"].lower() for q in pack["quiz"]]
    assert len(set(answers)) == len(answers), "no answer is blanked twice"
    assert not {"before", "next", "level"} & set(answers), "filler words are never blanked"
    assert not any(q["evidence"].startswith("Today") for q in pack["quiz"]), "discourse sentences are skipped"


def test_study_pack_without_generation_is_extractive():
    pack = wt.StudyPackBuilder(None, None).build(LECTURE, None, 2)
    assert pack["summary_method"] == "extractive" and pack["model"] is None
    with pytest.raises(wt.WritingToolError):
        wt.StudyPackBuilder(None, None).build("too short", None, 2)


# ------------------------------------------------------------------------------------------- question quality

def test_question_checker_flags_problems_and_keeps_good_questions_clean():
    checker = wt.QuestionQualityChecker(FakeGen("Which of the following is NOT a sorting algorithm?"))
    out = checker.check(["Which of the following is not a sorting algorithm except quicksort?", "Explain how breadth-first search finds the shortest path."])
    bad, good = out["results"]
    assert {"double_negative"} <= {i["code"] for i in bad["issues"]}
    assert bad["suggested_rewrite"] == "Which of the following is NOT a sorting algorithm?"
    assert good["issues"] == [] and good["score"] == 100 and good["suggested_rewrite"] is None
    assert good["bloom_level"] == "UNDERSTAND"


def test_question_rewrite_that_answers_or_drifts_off_topic_is_discarded():
    checker = wt.QuestionQualityChecker(FakeGen("Design a hotel booking system with three tables."))
    assert checker.rewrite("what is bfs and dfs explain") is None


def test_vague_verbs_and_unclear_tasks_are_flagged():
    codes = {i["code"] for i in wt.QuestionQualityChecker(None).issues("students should know about sorting")}
    assert {"vague_verb", "unclear_task", "grammar"} <= codes


# ------------------------------------------------------------------------------------------- simplify + Bloom

def test_simplify_reports_whether_the_rewrite_is_actually_easier():
    hard = "Deadlock constitutes a pathological synchronisation condition wherein concurrently executing processes indefinitely await resources."
    easy = "A deadlock is when programs get stuck. Each one waits for another. None can go on."
    out = wt.Simplifier(FakeGen(easy)).simplify(hard, "school")
    assert out["simpler"] is True and out["readability_after"]["grade_level"] < out["readability_before"]["grade_level"]

    harder = hard + " Furthermore, the aforementioned circumstances necessitate sophisticated intervention."
    out = wt.Simplifier(FakeGen(harder, harder)).simplify(hard, "school")
    assert out["simpler"] is False and "not measurably easier" in out["note"]


def test_simplify_and_bloom_need_a_generation_model():
    with pytest.raises(wt.WritingToolError):
        wt.Simplifier(None).simplify("A long enough passage of text to simplify for the class.", "school")
    with pytest.raises(wt.WritingToolError):
        wt.BloomRewriter(None).rewrite("Define breadth-first search.", "APPLY")


def test_bloom_rewrite_retries_until_level_and_topic_match():
    gen = FakeGen("Design a hotel booking system.", "Trace breadth-first search on a graph with five vertices starting from vertex A.")
    out = wt.BloomRewriter(gen).rewrite("Define breadth-first search.", "APPLY")
    assert out["original_level"] == "REMEMBER"
    assert out["rewritten"].startswith("Trace")
    assert out["on_topic"] is True and out["attempts"] == 2


def test_bloom_rewrite_reports_honestly_when_it_misses():
    gen = FakeGen("Design a hotel booking system.", "Design a hotel booking system.", "Design a hotel booking system.")
    out = wt.BloomRewriter(gen).rewrite("Define breadth-first search.", "CREATE")
    assert out["matches_target"] is True and out["on_topic"] is False


# ------------------------------------------------------------------------------------------- endpoints

def test_endpoints_validate_input():
    assert client.post("/api/v1/toolkit/bloom-rewrite", json={"question": "Define BFS.", "target_level": "GUESS"}).status_code == 422
    assert client.post("/api/v1/toolkit/simplify", json={"text": "x", "level": "phd"}).status_code == 422
    assert client.post("/api/v1/toolkit/speak", json={"text": "hello", "language": "fr"}).status_code == 422
    r = client.post("/api/v1/toolkit/question-quality", json={"questions": ["Explain BFS.", "list sorts"], "suggest_rewrites": False})
    assert r.status_code == 200 and len(r.json()["results"]) == 2


def test_study_pack_endpoint_works_without_any_model(monkeypatch):
    monkeypatch.setattr(get_generation_service(), "_model_name", None)
    r = client.post("/api/v1/toolkit/study-pack", json={"text": LECTURE, "num_questions": 2})
    assert r.status_code == 200
    assert r.json()["summary_method"] in ("extractive", "generative")
    assert len(r.json()["quiz"]) == 2
