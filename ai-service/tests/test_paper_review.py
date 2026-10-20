"""Exam paper review: Bloom balance, duplicates and topic coverage (fake embeddings, no model download)."""

from fastapi.testclient import TestClient

from app.main import app
from app.services import paper_review as pr
from app.services.huggingface_service import get_hf_service
from app.services.writing_tools import WritingToolError

client = TestClient(app)

PAPER = [
    {"text": "Q1. Define a graph.", "marks": 5},
    {"text": "2. List the properties of a binary search tree.", "marks": 5},
    {"text": "3. Explain how breadth-first search works.", "marks": 10},
    {"text": "4. Describe how BFS traverses a graph.", "marks": 10},
    {"text": "5. Calculate the shortest path from A to F using Dijkstra's algorithm.", "marks": 10},
    {"text": "6. Compare BFS and DFS.", "marks": 10},
]


class FakeHf:
    """Embeds each text as a fixed vector, so similarities are controlled by the test."""

    def __init__(self, table):
        self.table = table

    def generate_batch_embeddings(self, texts):
        return [self.table.get(t, [0.0, 0.0, 1.0]) for t in texts]


def test_bloom_balance_is_weighted_by_marks_and_flags_bands():
    r = pr.PaperReviewer(None).review(PAPER)
    bands = r["bloom"]["bands"]
    assert r["weighted_by"] == "marks" and r["total_marks"] == 50
    assert bands["lower"]["share"] == 60.0 and bands["lower"]["status"] == "high"  # Q1-Q4: 30 of 50 marks
    assert bands["higher"]["share"] == 0.0 and "EVALUATE" in r["bloom"]["missing_levels"]
    messages = " ".join(f["message"] for f in r["findings"])
    assert "60% of the marks test Remember / Understand" in messages
    assert "No question asks students to evaluate or create" in messages
    assert r["questions"][0]["text"] == "Define a graph."  # numbering stripped
    assert r["questions"][0]["issues"] == []  # short questions are fine in a paper


def test_given_bloom_levels_win_over_detection_and_missing_marks_count_equally():
    qs = [{"text": "Explain hashing.", "bloom_level": "EVALUATE"}, {"text": "Define a stack."}]
    r = pr.PaperReviewer(None).review(qs)
    assert r["questions"][0]["bloom_level"] == "EVALUATE" and r["questions"][0]["bloom_source"] == "given"
    assert r["questions"][1]["bloom_source"] == "detected"
    assert r["weighted_by"] == "question count" and r["total_marks"] is None
    assert any("each question counts equally" in f["message"] for f in r["findings"])


def test_semantic_duplicates_and_parallel_questions():
    q3, q4, q7 = "Explain how breadth-first search works.", "Describe how BFS traverses a graph.", "Explain how depth-first search works."
    hf = FakeHf({q3: [1.0, 0.0, 0.0], q4: [0.6, 0.8, 0.0], q7: [0.76, 0.65, 0.0]})
    r = pr.PaperReviewer(hf).review([{"text": q3}, {"text": q4}, {"text": q7}])
    pairs = {(d["a"], d["b"]): d["kind"] for d in r["duplicates"]}
    assert pairs.get(("1", "2")) == "overlap"  # reworded duplicate (cosine 0.6)
    assert ("1", "3") not in pairs  # same wording, different subject: not flagged
    assert r["similarity_method"] == "semantic"


def test_lexical_fallback_finds_near_identical_questions():
    r = pr.PaperReviewer(None).review([{"text": "Explain the working of a hash table with chaining."},
                                       {"text": "Explain the working of a hash table using chaining."}, {"text": "Define a queue."}])
    assert r["duplicates"][0]["kind"] == "duplicate" and r["similarity_method"] == "lexical"
    assert any("look like duplicates" in f["message"] for f in r["findings"])


def test_topic_coverage_reports_uncovered_topics():
    r = pr.PaperReviewer(None).review(PAPER, ["Shortest path algorithms", "Binary search trees", "Hashing"])
    covered = {t["topic"]: t for t in r["topics"]}
    assert covered["Shortest path algorithms"]["covered"] and covered["Shortest path algorithms"]["questions"] == ["5"]
    assert covered["Binary search trees"]["questions"] == ["2"]
    assert not covered["Hashing"]["covered"]
    assert any("Not covered by any question: Hashing" in f["message"] for f in r["findings"])


def test_custom_targets_and_validation():
    r = pr.PaperReviewer(None).review(PAPER, targets={"lower": (50.0, 70.0)})
    assert r["bloom"]["bands"]["lower"]["status"] == "ok"
    try:
        pr.PaperReviewer(None).review([{"text": "Define a graph."}])
        raise AssertionError("expected WritingToolError")
    except WritingToolError:
        pass


def test_endpoint():
    app.dependency_overrides[get_hf_service] = lambda: None
    try:
        res = client.post("/api/v1/toolkit/paper-review", json={"questions": PAPER, "topics": ["Hashing"], "targets": {"lower": [10, 40], "bad": "x"}})
        assert res.status_code == 200, res.text
        body = res.json()
        assert body["bloom"]["bands"]["lower"]["target"] == [10.0, 40.0]
        assert body["topics"][0]["covered"] is False
        assert client.post("/api/v1/toolkit/paper-review", json={"questions": [{"text": "Define a graph."}]}).status_code == 422
    finally:
        app.dependency_overrides.pop(get_hf_service, None)
