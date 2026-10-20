"""Feedback drafter: rubric-grounded, marks-free feedback, with a fake generator (no model download)."""

from fastapi.testclient import TestClient

from app.main import app
from app.services import feedback_drafter as fd
from app.services.generation_service import get_generation_service
from app.services.writing_tools import WritingToolError

client = TestClient(app)

QUESTION = "Explain breadth-first search and why it is useful."
CRITERIA = [
    {"criterion": "Definition of BFS", "description": "Explains how breadth-first search explores a graph",
     "indicators": ["Mentions that BFS explores level by level", "Mentions that BFS uses a queue"]},
    {"criterion": "Shortest path", "description": "Explains why BFS finds shortest paths in unweighted graphs",
     "indicators": ["States that BFS finds the shortest path in an unweighted graph"]},
    {"criterion": "Complexity", "description": "Gives the time complexity", "indicators": ["Gives time complexity O(V + E)"]},
]
PARTIAL_ANSWER = ("Breadth first search explores the graph level by level starting from a source vertex. "
                  "It uses a queue to store the vertices to visit next.")
FULL_ANSWER = (PARTIAL_ANSWER + " Because it visits vertices in order of distance, BFS finds the shortest path in an unweighted graph. "
               "Its time complexity is O(V + E).")


class FakeGen:
    is_configured = True
    model_name = "fake/model"

    def __init__(self, *replies):
        self.replies = list(replies)
        self.prompts = []

    def generate(self, prompt, max_new_tokens=None, completion=False):
        self.prompts.append(prompt)
        return self.replies.pop(0) if self.replies else None


def test_student_phrase_strips_marker_verbs_and_keeps_acronyms():
    assert fd.student_phrase("Mentions that BFS uses a queue (only partially evident)") == "BFS uses a queue"
    assert fd.student_phrase("The student correctly explains the products of photosynthesis") == "the products of photosynthesis"
    assert fd.student_phrase("No clear discussion of: Complexity") == "complexity"


def test_feedback_names_found_evidence_and_missing_points_without_marks():
    r = fd.FeedbackDrafter(None, None).draft(QUESTION, CRITERIA, PARTIAL_ANSWER, "encouraging")
    text = r["feedback"]
    assert "level by level" in text  # evidence quoted back from the answer
    assert "shortest path" in text and "O(V + E)" in text  # missing points named
    assert "Next step" in text
    assert not fd.GRADE_WORDS.search(text)
    assert fd.GRADE_WORDS.search("You scored 7/10") and fd.GRADE_WORDS.search("worth 3 points")
    assert r["method"] == "rubric_template"
    assert "suggested_marks" not in str(r) and "max_marks" not in str(r)
    levels = {c["criterion"]: c["coverage_level"] for c in r["criteria"]}
    assert levels["Shortest path"] == "NOT_ADDRESSED" and levels["Definition of BFS"] in ("STRONG", "PARTIAL")


def test_complete_answer_gets_positive_opening_and_no_missing_points():
    r = fd.FeedbackDrafter(None, None).draft(QUESTION, CRITERIA, FULL_ANSWER, "neutral")
    assert r["feedback"].startswith(fd.OPENINGS["neutral"]["strong"])
    assert not [i for i in r["improvements"] if i.startswith("Add:")]


def test_concise_tone_is_short():
    r = fd.FeedbackDrafter(None, None).draft(QUESTION, CRITERIA, PARTIAL_ANSWER, "concise")
    assert len(r["feedback"]) < len(fd.FeedbackDrafter(None, None).draft(QUESTION, CRITERIA, PARTIAL_ANSWER, "neutral")["feedback"])
    assert len(r["improvements"]) == 1


def test_good_ai_rewrite_is_used():
    reply = ("Thank you for your answer. You explained well that breadth first search explores the graph level by level. "
             "To complete it, explain why BFS finds the shortest path in an unweighted graph and give its time complexity O(V + E). "
             "Also say more about how the queue is used. Next step: add a sentence on the shortest path.")
    gen = FakeGen(reply)
    r = fd.FeedbackDrafter(gen, None).draft(QUESTION, CRITERIA, PARTIAL_ANSWER, "encouraging", polish=True)
    assert r["method"] == "ai_polished" and r["feedback"] == reply and r["model"] == "fake/model"
    assert "Do not mention marks" in gen.prompts[0]


def test_ai_rewrite_mentioning_marks_is_rejected():
    reply = "Good effort, you get 4/10 marks. Explain the shortest path and the time complexity O(V + E) next time."
    r = fd.FeedbackDrafter(FakeGen(reply), None).draft(QUESTION, CRITERIA, PARTIAL_ANSWER, polish=True)
    assert r["method"] == "rubric_template" and "marks" not in r["feedback"]


def test_ai_rewrite_dropping_a_missing_point_is_rejected():
    reply = "Thank you for your answer. You explained the level by level search well. Next step: give the time complexity O(V + E)."
    r = fd.FeedbackDrafter(FakeGen(reply), None).draft(QUESTION, CRITERIA, PARTIAL_ANSWER, polish=True)
    assert r["method"] == "rubric_template"  # never mentions the shortest path


def test_ai_rewrite_copying_the_worked_example_or_inventing_numbers_is_rejected():
    copied = "Good start: you explained chlorophyll. Also state the shortest path and complexity O(V + E)."
    invented = "Good start. Explain the shortest path, which takes 12 steps here, and give the complexity O(V + E)."
    assert fd.FeedbackDrafter(FakeGen(copied), None).draft(QUESTION, CRITERIA, PARTIAL_ANSWER, polish=True)["method"] == "rubric_template"
    assert fd.FeedbackDrafter(FakeGen(invented), None).draft(QUESTION, CRITERIA, PARTIAL_ANSWER, polish=True)["method"] == "rubric_template"


def test_ai_rewrite_runs_only_on_the_hosted_provider_by_default():
    from app.services import provider_context
    good = ("You explained well that breadth first search explores level by level. Explain why BFS finds the shortest path "
            "in an unweighted graph, say more about the queue, and give the time complexity O(V + E).")
    gen = FakeGen(good)
    assert fd.FeedbackDrafter(gen, None).draft(QUESTION, CRITERIA, PARTIAL_ANSWER)["method"] == "rubric_template"
    assert gen.prompts == []
    provider_context.set_requested_provider("hosted")
    try:
        assert fd.FeedbackDrafter(FakeGen(good), None).draft(QUESTION, CRITERIA, PARTIAL_ANSWER)["method"] == "ai_polished"
    finally:
        provider_context.set_requested_provider(None)


def test_semantic_false_match_is_not_praised():
    """The engine may match an on-topic sentence to the wrong criterion; the word check removes it."""
    analysis = {"criterion_results": [
        {"criterion": "Shortest path", "coverage_level": "PARTIAL", "missing_elements": [],
         "evidence": ["Breadth first search explores the graph level by level starting from a source vertex."]},
    ]}
    fd.FeedbackDrafter._reconcile(analysis, [CRITERIA[1]], PARTIAL_ANSWER)
    r = analysis["criterion_results"][0]
    assert r["evidence"] == [] and r["coverage_level"] == "NOT_ADDRESSED"
    assert "States that BFS finds the shortest path in an unweighted graph" in r["missing_elements"]


def test_partly_evident_point_without_any_of_its_words_is_reported_missing():
    crit = [{"criterion": "Trade-off", "description": "Notes the cost of indexes", "indicators": ["Mentions slower inserts and updates"]}]
    analysis = {"criterion_results": [
        {"criterion": "Trade-off", "coverage_level": "PARTIAL", "evidence": [],
         "missing_elements": ["Mentions slower inserts and updates (only partially evident)"]},
    ]}
    fd.FeedbackDrafter._reconcile(analysis, crit, "An index stores keys in a sorted B+ tree, so lookups are faster.")
    r = analysis["criterion_results"][0]
    assert r["missing_elements"] == ["Mentions slower inserts and updates"] and r["coverage_level"] == "NOT_ADDRESSED"


def test_stemmer_matches_word_forms():
    assert fd._stem("scanning") == fd._stem("scan") == fd._stem("scanned")
    assert fd._stem("updates") == fd._stem("update") and fd._stem("tables") == fd._stem("table")
    assert fd._stem("class") == "class"


def test_input_validation():
    d = fd.FeedbackDrafter(None, None)
    for args in [("", CRITERIA, PARTIAL_ANSWER), (QUESTION, [], PARTIAL_ANSWER), (QUESTION, CRITERIA, "BFS")]:
        try:
            d.draft(*args)
            raise AssertionError("expected WritingToolError")
        except WritingToolError:
            pass


def test_endpoint_returns_feedback_and_requires_criteria():
    app.dependency_overrides[get_generation_service] = lambda: FakeGen()
    try:
        ok = client.post("/api/v1/toolkit/feedback-draft", json={"question": QUESTION, "answer": PARTIAL_ANSWER, "criteria": CRITERIA, "tone": "neutral"})
        assert ok.status_code == 200, ok.text
        body = ok.json()
        assert body["status"] == "success" and "shortest path" in body["feedback"] and "suggested_marks" not in body
        bad = client.post("/api/v1/toolkit/feedback-draft", json={"question": QUESTION, "answer": PARTIAL_ANSWER, "criteria": []})
        assert bad.status_code == 422
    finally:
        app.dependency_overrides.pop(get_generation_service, None)
