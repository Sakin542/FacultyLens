"""STEP 44: tests for the evaluation harness — metric maths, dataset integrity and result classification.

These tests never call the embedding model; component evaluators are exercised by the
`python -m evaluation.run` command and by the persisted baseline results.
"""

import json
from collections import Counter
from pathlib import Path

import pytest

from evaluation import metrics as M
from evaluation.common import DATASETS_DIR, Catalogue, classify, load_dataset, load_gates
from evaluation.components.question_generation import check_question, hallucination_check
from evaluation.components.rubric import check_rubric
from evaluation.taxonomy import ERROR_TYPES, assert_known, from_step35

# ---------------------------------------------------------------- metrics


def test_classification_report_hand_verified():
    exp = ["A", "A", "B", "B", "C"]
    pred = ["A", "B", "B", "B", "A"]
    rep = M.classification_report(exp, pred, ["A", "B", "C"])
    assert rep["accuracy"] == 0.6
    rows = {r["label"]: r for r in rep["per_class"]}
    assert rows["A"]["precision"] == 0.5 and rows["A"]["recall"] == 0.5
    assert rows["B"]["precision"] == pytest.approx(2 / 3, abs=1e-4) and rows["B"]["recall"] == 1.0
    assert rows["C"]["f1"] == 0.0
    assert rep["macro_f1"] == pytest.approx((0.5 + 0.8 + 0.0) / 3, abs=1e-3)
    assert rep["confusion_matrix"]["C"]["A"] == 1


def test_undefined_metrics_return_none_not_zero():
    assert M.classification_report([], [])["accuracy"] is None
    assert M.wilson_interval(0, 0) is None
    assert M.spearman([1, 2], [1, 2]) is None
    assert M.recall_at_k(["a"], [], 3) is None
    assert M.error_stats([])["mae"] is None
    assert M.bootstrap_ci([1, 2, 3], lambda v: sum(v) / len(v)) is None


def test_wilson_interval_bounds():
    lo, hi = M.wilson_interval(72, 72)
    assert lo > 0.9 and hi == 1.0
    lo, hi = M.wilson_interval(0, 20)
    assert lo == 0.0 and hi < 0.2


def test_rank_correlation():
    assert M.spearman([1, 2, 3, 4], [10, 20, 30, 40]) == 1.0
    assert M.spearman([1, 2, 3, 4], [40, 30, 20, 10]) == -1.0
    assert M.pearson([1, 2, 3], [2, 4, 6]) == 1.0


def test_ranking_metrics():
    ranked = ["q3", "q1", "q9"]
    assert M.precision_at_k(ranked, {"q1", "q9"}, 3) == pytest.approx(2 / 3, abs=1e-4)
    assert M.recall_at_k(ranked, {"q1", "q9", "q7"}, 3) == pytest.approx(2 / 3, abs=1e-4)
    assert M.reciprocal_rank(ranked, {"q1"}) == 0.5
    assert M.reciprocal_rank(ranked, {"zzz"}) == 0.0
    assert M.ndcg_at_k(["a", "b"], {"a": 2, "b": 1}, 2) == 1.0
    assert M.ndcg_at_k(["b", "a"], {"a": 2, "b": 1}, 2) < 1.0


def test_error_stats_and_tolerance():
    pairs = [(8.0, 7.5), (5.0, 5.0), (2.0, 4.0)]
    s = M.error_stats(pairs)
    assert s["mae"] == pytest.approx(0.8333, abs=1e-3)
    assert s["mean_error"] == pytest.approx(-0.5, abs=1e-6)
    assert M.within_tolerance_rate(pairs, 0.5) == pytest.approx(2 / 3, abs=1e-3)


def test_calibration_and_multilabel():
    cal = M.calibration_table([0.9, 0.9, 0.6, 0.6], [True, False, True, True], bins=2)
    assert cal["ece"] is not None and 0 <= cal["ece"] <= 1
    ml = M.multilabel_report([{"a", "b"}, {"c"}], [{"a"}, {"c", "d"}], universe_size=4)
    assert ml["micro_precision"] == pytest.approx(2 / 3, abs=1e-3)
    assert ml["micro_recall"] == pytest.approx(2 / 3, abs=1e-3)
    assert ml["hamming_loss"] == 0.25


def test_cohen_kappa():
    assert M.cohen_kappa(["a", "b", "a", "b"], ["a", "b", "a", "b"]) == 1.0
    assert M.cohen_kappa(["a", "a", "b", "b"], ["a", "b", "a", "b"]) == 0.0


# ---------------------------------------------------------------- taxonomy & gates


def test_error_taxonomy_matches_step44_spec():
    assert ERROR_TYPES[:3] == ["WRONG_CLASSIFICATION", "WRONG_DIFFICULTY", "WRONG_BLOOM_LEVEL"]
    assert len(ERROR_TYPES) == 16
    assert from_step35("WRONG_CLASS") == "WRONG_CLASSIFICATION"
    assert from_step35("INFERENCE_ERROR") == "MODEL_FAILURE"
    assert from_step35(None) is None
    with pytest.raises(ValueError):
        assert_known("SOMETHING_ELSE")


def test_result_classification_never_fabricates():
    gates = load_gates()
    assert classify("QUESTION_CLASSIFICATION", None, 100, gates) == "NOT_EVALUATED"
    assert classify("QUESTION_CLASSIFICATION", 0.95, 5, gates) == "INSUFFICIENT_DATA"
    assert classify("QUESTION_CLASSIFICATION", 0.95, 100, gates) == "EXCELLENT"
    assert classify("QUESTION_CLASSIFICATION", 0.75, 100, gates) == "ACCEPTABLE"
    assert classify("QUESTION_CLASSIFICATION", 0.50, 100, gates) == "NEEDS_IMPROVEMENT"
    assert classify("AI_GRADING", 0.04, 30, gates) == "EXCELLENT"  # lower is better
    assert classify("AI_GRADING", 0.30, 30, gates) == "NEEDS_IMPROVEMENT"


# ---------------------------------------------------------------- datasets


def test_question_dataset_integrity():
    cat = Catalogue()
    qs = list(cat.questions.values())
    assert len(qs) >= 100
    types = {"MCQ", "SHORT_ANSWER", "DESCRIPTIVE", "PROBLEM_SOLVING", "TRUE_FALSE", "CONCEPTUAL", "ANALYTICAL"}
    bloom = {"REMEMBER", "UNDERSTAND", "APPLY", "ANALYZE", "EVALUATE", "CREATE"}
    for q in qs:
        assert q["expected_type"] in types, q["id"]
        assert q["expected_difficulty"] in {"EASY", "MEDIUM", "HARD"}, q["id"]
        assert q["expected_cognitive_level"] in bloom, q["id"]
        assert q["split"] in {"DEV", "VALIDATION", "TEST"}
        assert q["expected_topic"] in cat.topics(q["course"]), q["id"]
        for t in q.get("acceptable_topics", []):
            assert t in cat.topics(q["course"]), q["id"]
        if q["learning_outcome"]:
            assert q["learning_outcome"] in cat.los(q["course"]), q["id"]
        assert q["marks"] > 0
    # every label value occurs (no class is missing from the benchmark)
    assert set(Counter(q["expected_type"] for q in qs)) == types
    assert set(Counter(q["expected_cognitive_level"] for q in qs)) == bloom
    assert len(cat.select("TEST")) >= 60
    assert len({q["id"] for q in qs}) == len(qs)


def test_pair_datasets_reference_existing_items():
    cat = Catalogue()
    for p in load_dataset("lo_pairs")["pairs"]:
        cat.question(p["question_id"])
        cat.lo_text(p["lo"])
        assert p["expected_alignment"] in {"STRONG", "WEAK", "NOT_ALIGNED"}
    sim = load_dataset("similarity_pairs")["pairs"]
    assert Counter(p["expected_relationship"] for p in sim).keys() == {"POTENTIAL_DUPLICATE", "HIGHLY_SIMILAR", "SOMEWHAT_SIMILAR", "NOT_SIMILAR"}
    for p in sim:
        assert 0.0 <= p["expert_score"] <= 1.0
    for q in load_dataset("retrieval")["queries"]:
        for qid in q["relevant"]:
            cat.question(qid)
    for case in load_dataset("assessment_cases")["cases"]:
        for qid in case["question_ids"]:
            cat.question(qid)
        assert set(case["expected_flags"]) == {"topic_coverage", "learning_outcome_coverage", "difficulty_balance", "cognitive_diversity", "question_diversity", "marks_distribution"}


def test_grading_benchmark_rubrics_sum_to_total():
    for item in load_dataset("grading")["items"]:
        assert round(sum(c["max_marks"] for c in item["rubric"]), 2) == item["total_marks"], item["id"]
        for a in item["answers"]:
            assert 0 <= a["faculty_marks"] <= item["total_marks"], a["id"]
            assert a["rationale"]


def test_rag_corpus_chunks_are_unique_and_queries_reference_them():
    rag = load_dataset("rag")
    ids = [c["chunk_id"] for d in rag["documents"] for c in d["chunks"]]
    assert len(ids) == len(set(ids))
    for q in rag["queries"]:
        for cid in q["relevant_chunks"]:
            assert int(cid) in ids, q["id"]
        if q["answer_present"]:
            assert q["answer_keywords"], q["id"]


def test_datasets_contain_no_secrets():
    forbidden = ("hf_", "sk-", "password=", "Bearer ", "@gmail", "AKIA")
    for path in DATASETS_DIR.glob("*.json"):
        text = path.read_text(encoding="utf-8")
        for f in forbidden:
            assert f not in text, f"{path.name} contains {f!r}"


# ---------------------------------------------------------------- component checks (pure)


def test_rubric_check_detects_marks_mismatch():
    good = {"criteria": [{"criterion": "A", "description": "d", "max_marks": 4, "sort_order": 1}, {"criterion": "B", "description": "d", "max_marks": 6, "sort_order": 2}]}
    assert all(check_rubric(good, 10, 8).values())
    bad = {"criteria": [{"criterion": "A", "description": "d", "max_marks": 4, "sort_order": 1}, {"criterion": "A", "description": "", "max_marks": 5, "sort_order": 2}]}
    checks = check_rubric(bad, 10, 8)
    assert checks["marks_sum_equals_total"] is False
    assert checks["criteria_unique"] is False
    assert checks["descriptions_present"] is False


def test_hallucination_proxy_flags_unsupported_domain_terms():
    evidence = "Shortest Job First selects the process with the smallest CPU burst."
    vocab = {"shortest", "burst", "process", "kubernetes", "scheduling"}
    ok = hallucination_check("Explain how Shortest Job First chooses the next process.", evidence, "Process Management and Scheduling", vocab)
    assert ok["flag"] is False
    bad = hallucination_check("Explain how Kubernetes schedules the next process.", evidence, "Process Management and Scheduling", vocab)
    assert bad["flag"] is True and "kubernetes" in bad["unsupported_terms"]


def test_question_constraint_checks_are_independent_of_generator_validation():
    class _Ctx:
        hf = None
        settings = type("S", (), {"similarity_duplicate_threshold": 0.85})()

    spec = {"topic": "Normalization", "learning_outcome": "LO3"}
    slot = {"question_type": "MCQ", "difficulty_level": "EASY", "cognitive_level": "REMEMBER", "marks": 1}
    gq = {"question_text": "Which normal form removes partial dependencies? Choose one.", "question_type": "MCQ", "marks": 1.0, "difficulty_level": "EASY", "cognitive_level": "REMEMBER",
          "options": ["1NF", "2NF", "3NF"], "correct_option": "2NF", "validation": {"co_alignment_status": "WEAK", "overall_status": "PASSED"}}
    checks = check_question(_Ctx(), spec, slot, gq, [])
    assert all(v for k, v in checks.items() if v is not None and not k.startswith("_"))
    gq_bad = dict(gq, marks=2.0, options=None, difficulty_level="HARD")
    checks = check_question(_Ctx(), spec, slot, gq_bad, [])
    assert checks["marks"] is False and checks["format"] is False and checks["difficulty"] is False


def test_baseline_results_are_real_runs():
    """The committed baseline must carry provenance and headline values produced by an actual run."""
    base = Path(__file__).resolve().parents[1] / "evaluation" / "results" / "baseline"
    if not base.exists():
        pytest.skip("no baseline committed")
    prov = json.loads((base / "provenance.json").read_text(encoding="utf-8"))
    assert prov["embedding_model"]["model_name"] == "sentence-transformers/all-MiniLM-L6-v2"
    assert prov["datasets"]["questions"]["version"]
    summary = json.loads((base / "summary.json").read_text(encoding="utf-8"))
    evaluated = [c for c in summary["components"] if c["status"] not in ("NOT_EVALUATED",)]
    assert len(evaluated) >= 10
    for c in evaluated:
        assert c["sample_size"] > 0
        assert c["headline"]["value"] is not None
