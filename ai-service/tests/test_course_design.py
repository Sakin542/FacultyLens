from fastapi.testclient import TestClient

from app.main import app
from app.services.course_design import LessonPlanner, SyllabusDrafter, extract_topics
from app.services.paper_summarizer import PaperSummarizer

client = TestClient(app)

DESCRIPTION = (
    "Relational data model, entity-relationship modelling, SQL queries, normalization, indexing and B+ trees, "
    "query processing, transactions and concurrency control, recovery, and NoSQL databases."
)
POS = [
    {"code": "PO1", "title": "Engineering knowledge", "description": "Apply knowledge of mathematics, science and computing to solve problems."},
    {"code": "PO3", "title": "Design of solutions", "description": "Design systems, components and processes that meet specified needs."},
    {"code": "PO5", "title": "Modern tool usage", "description": "Use modern tools such as SQL database systems and programming environments."},
]


class FakeHF:
    """Bag-of-words 'embeddings' so tests exercise the embedding path deterministically."""

    is_loaded = True
    VOCAB = ["sql", "design", "database", "queries", "normalization", "transactions", "mathematics", "tools", "solve", "systems",
             "index", "trees", "concurrency", "recovery", "model", "relational", "results", "accuracy", "method", "dataset"]

    def generate_batch_embeddings(self, texts):
        return [[t.lower().count(w) + 0.01 for w in self.VOCAB] for t in texts]


def test_short_acronyms_are_topics_and_course_title_is_not():
    out = SyllabusDrafter().draft("CSE311", "Database Management Systems", "Relational models, SQL, indexing, transaction processing, and database normalization.", 3, 14, POS)
    assert out["topics"][:2] == ["Relational models", "SQL"]
    assert "Database Management Systems" not in out["topics"]
    labels = [w["topic"] for w in out["weekly_outline"] if w["kind"] == "teaching"]
    assert len(labels) == len(set(labels)), "repeated topics are numbered as parts"
    assert any(label.endswith("(part 2)") for label in labels)


def test_extract_topics_from_description():
    topics = extract_topics(DESCRIPTION)
    assert "SQL queries" in topics
    assert any(t.lower().startswith("indexing") or t.lower() == "b+ trees" for t in topics)
    assert len(topics) >= 6


def test_syllabus_draft_bloom_progression_mapping_and_outline():
    out = SyllabusDrafter(hf_service=FakeHF()).draft("CSE311", "Database Systems", DESCRIPTION, 3, 14, POS)
    assert [o["cognitive_level"] for o in out["outcomes"]] == ["Understand", "Apply", "Analyze", "Evaluate", "Create"]
    assert out["outcomes"][1]["statement"].startswith("Use") or out["outcomes"][1]["statement"].startswith("Apply")
    assert out["co_po_method"] == "embedding"
    # Every CO maps to at least one PO, with evidence
    assert {link["co"] for link in out["co_po"]} == {o["code"] for o in out["outcomes"]}
    assert all(1 <= link["level"] <= 3 and link["justification"] for link in out["co_po"])
    weeks = [w["week"] for w in out["weekly_outline"]]
    assert weeks == list(range(1, 15))
    assert next(w for w in out["weekly_outline"] if w["week"] == 7)["kind"] == "exam"
    assert sum(c["weight"] for c in out["assessment_plan"]) == 100
    assert out["engine"] == "template"


def test_syllabus_without_model_uses_lexical_mapping_and_warns():
    out = SyllabusDrafter().draft("CSE311", "Database Systems", DESCRIPTION, 3, 14, POS)
    assert out["co_po_method"] == "lexical"
    assert any("keyword overlap" in w for w in out["warnings"])


def test_lesson_plan_covers_every_outcome_and_schedules_assessments():
    outcomes = [
        {"code": "CO1", "description": "Explain the relational data model and SQL queries", "cognitive_level": "Understand"},
        {"code": "CO2", "description": "Apply normalization to database design", "cognitive_level": "Apply"},
        {"code": "CO3", "description": "Design a database system with transactions and recovery", "cognitive_level": "Create"},
    ]
    plan = LessonPlanner(hf_service=FakeHF()).plan("Database Systems", outcomes, 14, 2, 75,
                                                   ["Relational model and SQL queries", "Normalization", "Indexing and B+ trees", "Transactions and recovery", "Database design project"])
    assert len(plan["weeks"]) == 14
    teaching = [w for w in plan["weeks"] if w["kind"] == "teaching"]
    assert all(len(w["sessions"]) == 2 for w in teaching)
    assert all(plan["outcome_coverage"][c] > 0 for c in ("CO1", "CO2", "CO3"))
    types = [a["type"] for a in plan["assessments"]]
    assert "midterm" in types and "final" in types and "assignment_due" in types
    topics = [w["topic"] for w in teaching]
    assert len(topics) == len(set(topics)) and "Normalization (part 2)" in topics
    create_week = next(w for w in teaching if "CO3" in w["outcomes"] and w["bloom_focus"] == "Create")
    assert create_week["sessions"][1]["kind"] == "activity"


PAPER = """Attention-Based Grading of Short Answers
Jane Smith, Rahim Uddin and Maria Lopez
Department of Computer Science, Example University
Abstract
We propose a transformer model for grading short student answers. Our approach uses attention over reference answers and rubric criteria. Results show that the model achieves 91.2% agreement with expert graders on a dataset of 12,000 answers. It outperforms the strongest baseline by 6.4 points in F1.
Keywords: automated grading; transformers; education
1. Introduction
Grading short answers is time consuming for faculty and is often inconsistent across graders. Prior systems relied on keyword overlap and could not handle paraphrased answers [1]. Recent work applies neural models to grading but rarely explains its decisions [2], [3].
2. Methods
We collected 12,000 answers from three university courses and had two experts grade each answer. Our model is based on a pre-trained encoder that attends to the rubric criteria and to reference answers. We train the model with cross-entropy loss for five epochs.
3. Results
The model achieves 91.2% exact agreement and a quadratic weighted kappa of 0.87 with expert graders. Removing rubric attention reduces agreement by 4.1%, which shows that the rubric signal matters. Agreement is significantly higher than the keyword baseline (p < 0.01) as reported in [1].
4. Conclusion
Rubric-aware attention makes automated grading both more accurate and easier to audit. A limitation of this study is that all courses were in computer science, so generalization to other disciplines is untested. Future work will evaluate the model on essays and on languages other than English.
References
[1] A. Brown and B. Green, "Keyword-based short answer grading," Journal of Educational Computing, 2015.
[2] C. White, "Neural models for answer scoring," in Proc. AIED, 2019.
[3] D. Black, "Explainable assessment with transformers," arXiv preprint, 2021.
"""


def test_paper_summary_structure_findings_citation_and_references():
    out = PaperSummarizer(hf_service=FakeHF()).summarize(PAPER)
    assert out["title"] == "Attention-Based Grading of Short Answers"
    assert out["authors"] == ["Jane Smith", "Rahim Uddin", "Maria Lopez"]
    assert out["keywords"] == ["automated grading", "transformers", "education"]
    assert {"abstract", "introduction", "methods", "results", "conclusion", "references"} <= set(out["sections_found"])
    assert all(s in PAPER.replace("\n", " ") for s in out["summary"])  # extractive: sentences come from the paper
    assert any("91.2%" in f for f in out["key_findings"])
    assert any("limitation" in s.lower() for s in out["limitations"])
    # Related-work claims ("Prior systems ... could not") are not this paper's limitations
    assert not any("Prior systems" in s for s in out["limitations"])
    # No year on the title page: the 2015 in the references must not be used
    assert out["year"] is None
    assert out["citation"]["apa"] == "Smith, J., Uddin, R., & Lopez, M. (n.d.). Attention-Based Grading of Short Answers."
    assert out["citation"]["ieee"] == 'J. Smith, R. Uddin, and M. Lopez, "Attention-Based Grading of Short Answers," n.d.'
    refs = out["references"]
    assert refs[0]["cited_in_text"] == 2 and "Keyword-based" in refs[0]["text"]


def test_short_text_is_rejected_by_the_endpoint():
    res = client.post("/api/v1/summarize-paper", json={"text": "too short " * 30})
    assert res.status_code == 422


def test_endpoints_return_drafts():
    res = client.post("/api/v1/syllabus/draft", json={"course_code": "CSE311", "course_name": "Database Systems", "description": DESCRIPTION, "program_outcomes": POS})
    assert res.status_code == 200 and len(res.json()["data"]["outcomes"]) == 5
    res = client.post("/api/v1/lesson-plan", json={"course_name": "Database Systems", "outcomes": [{"code": "CO1", "description": "Explain SQL", "cognitive_level": "Understand"}]})
    assert res.status_code == 200 and res.json()["data"]["weeks"]
