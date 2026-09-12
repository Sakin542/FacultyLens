"""Consistency: identical inputs sent N times (embedding cache cleared between repeats)."""

from __future__ import annotations

from typing import Any, Dict, List

from app.schemas.alignment import LearningOutcomeItem, QuestionItem, ThresholdsConfig
from app.schemas.similarity import CurrentQuestionItem, PreviousQuestionItem
from app.services.grading_engine import GradingEngine
from app.services.lo_matcher import LearningOutcomeMatcher
from app.services.question_analyzer import QuestionAnalyzer
from app.services.question_generator import QuestionGenerator
from app.services.rubric_generator import RubricGenerator
from app.services.semantic_similarity_analyzer import SemanticSimilarityAnalyzer
from evaluation import metrics as M
from evaluation.common import ComponentResult, finalize, load_dataset, truncate
from evaluation.components import Context
from evaluation.components.grading import build_request as grading_request
from evaluation.components.question_generation import build_request as qgen_request
from evaluation.components.rubric import build_request as rubric_request

SCORE_TOLERANCE = 1e-6


def evaluate(ctx: Context) -> List[ComponentResult]:
    res = ComponentResult(component="CONSISTENCY", split=ctx.split)
    repeats = max(2, ctx.repeats)
    checks: Dict[str, Dict[str, Any]] = {}

    def _clear_cache() -> None:
        cache = getattr(ctx.hf, "cache", None)
        if cache is not None:
            cache.clear()

    # 1. classification labels
    questions = ctx.catalogue.select(ctx.split)[:40]
    analyzer = QuestionAnalyzer(hf_service=ctx.hf)
    runs = []
    for _ in range(repeats):
        _clear_cache()
        runs.append([(o["classification"]["question_type"], o["difficulty"]["level"], o["cognitive_level"]["level"], o["topics"][0]["name"] if o["topics"] else None)
                     for o in (analyzer.analyze_single(q["question"], course_topics=ctx.catalogue.topics(q["course"])) for q in questions)])
    checks["question_analysis"] = _label_consistency(res, "question_analysis", [q["id"] for q in questions], runs, [truncate(q["question"], 80) for q in questions])

    # 2. LO alignment status + score
    matcher = LearningOutcomeMatcher(hf_service=ctx.hf)
    pairs = load_dataset("lo_pairs")["pairs"][:40]
    runs, score_runs = [], []
    for _ in range(repeats):
        _clear_cache()
        labels, scores = [], []
        for p in pairs:
            q = ctx.catalogue.question(p["question_id"])
            _, code, desc = ctx.catalogue.lo_text(p["lo"])
            out = matcher.match_questions_to_los([QuestionItem(id=q["id"], text=q["question"])], [LearningOutcomeItem(id=code, code=code, description=desc)], ThresholdsConfig())[0]
            labels.append((out["alignment_status"],))
            scores.append(out["similarity_score"])
        runs.append(labels)
        score_runs.append(scores)
    checks["lo_alignment"] = _label_consistency(res, "lo_alignment", [p["id"] for p in pairs], runs, [p["id"] for p in pairs]) | {"max_score_range": _max_range(score_runs)}

    # 3. similarity scores
    sim = SemanticSimilarityAnalyzer(hf_service=ctx.hf)
    spairs = load_dataset("similarity_pairs")["pairs"][:40]
    runs, score_runs = [], []
    for _ in range(repeats):
        _clear_cache()
        labels, scores = [], []
        for p in spairs:
            r = sim.analyze([CurrentQuestionItem(id="a", text=p["question_a"])], [PreviousQuestionItem(id="b", text=p["question_b"])], top_k=1)["results"][0]
            labels.append((r["max_similarity_status"],))
            scores.append(r["max_similarity_score"])
        runs.append(labels)
        score_runs.append(scores)
    checks["similarity"] = _label_consistency(res, "similarity", [p["id"] for p in spairs], runs, [p["id"] for p in spairs]) | {"max_score_range": _max_range(score_runs)}

    # 4. grading marks
    engine = GradingEngine(hf_service=ctx.hf, text_generation_service=ctx.text_generation)
    items = load_dataset("grading")["items"]
    answers = [(it, a) for it in items for a in it["answers"]]
    runs, score_runs = [], []
    for _ in range(repeats):
        _clear_cache()
        marks = [engine.grade(grading_request(it, a))["suggested_marks"] for it, a in answers]
        runs.append([(str(m),) for m in marks])
        score_runs.append(marks)
    checks["grading"] = _label_consistency(res, "grading", [a["id"] for _, a in answers], runs, [a["id"] for _, a in answers]) | {"max_marks_range": _max_range(score_runs)}

    # 5. rubric structure
    rg = RubricGenerator(hf_service=ctx.hf, text_generation_service=ctx.text_generation)
    rid = load_dataset("generation")["rubric_question_ids"][:20]
    runs = []
    for _ in range(repeats):
        _clear_cache()
        runs.append([(tuple((c["criterion"], c["max_marks"]) for c in rg.generate(rubric_request(ctx, ctx.catalogue.question(q)))["rubric"]["criteria"]),) for q in rid])
    checks["rubric_generation"] = _label_consistency(res, "rubric_generation", rid, runs, rid)

    # 6. question generation (acceptable variation for generative models: same type/marks/count; template engine: identical text)
    qg = QuestionGenerator(generation_service=ctx.generation, hf_service=ctx.hf)
    rag = load_dataset("rag")
    rag_chunks = {c["chunk_id"]: {**c, "document_id": d["document_id"], "document_name": d["document_name"]} for d in rag["documents"] for c in d["chunks"]}
    specs = load_dataset("generation")["question_generation_requests"][:10]
    generative = bool(getattr(ctx.generation, "is_configured", False))
    runs = []
    for _ in range(repeats):
        _clear_cache()
        row = []
        for spec in specs:
            out = qg.generate(qgen_request(ctx, spec, rag_chunks))
            sig = tuple((q["question_type"], q["marks"], None if generative else q["question_text"]) for q in out["questions"])
            row.append((sig,))
        runs.append(row)
    checks["question_generation"] = _label_consistency(res, "question_generation", [s["id"] for s in specs], runs, [s["id"] for s in specs]) | {"variation_policy": "type/marks/count only" if generative else "identical text (template engine)"}

    total = sum(c["items"] for c in checks.values())
    consistent = sum(c["consistent_items"] for c in checks.values())
    res.sample_size = total
    res.metrics = {"label_consistency": round(consistent / total, 4) if total else None, "label_consistency_ci95": M.wilson_interval(consistent, total), "repeats": repeats,
                   "per_component": {k: v["consistency"] for k, v in checks.items()},
                   "max_score_range": max([v.get("max_score_range") or 0.0 for v in checks.values()] + [checks["grading"].get("max_marks_range") or 0.0])}
    res.structured = {"checks": checks}
    res.notes += [f"Each input was evaluated {repeats} times with the embedding LRU cache cleared between repeats, so vectors were recomputed by the model each time.",
                  "All engines run greedy/deterministic code paths in this configuration; for generative models only type/marks/count are required to match."]
    return [finalize(res, ctx.gates, res.metrics["label_consistency_ci95"])]


def _label_consistency(res: ComponentResult, name: str, ids: List[str], runs: List[List[Any]], labels: List[str]) -> Dict[str, Any]:
    n = len(ids)
    consistent = 0
    for i in range(n):
        values = {tuple(run[i]) for run in runs}
        if len(values) == 1:
            consistent += 1
        else:
            res.errors.append({"id": ids[i], "component": name, "input": labels[i], "outputs": [str(run[i]) for run in runs], "error_type": "INCONSISTENT_OUTPUT"})
    return {"items": n, "consistent_items": consistent, "consistency": round(consistent / n, 4) if n else None}


def _max_range(score_runs: List[List[float]]) -> float:
    if not score_runs or not score_runs[0]:
        return 0.0
    return round(max(max(r[i] for r in score_runs) - min(r[i] for r in score_runs) for i in range(len(score_runs[0]))), 6)
