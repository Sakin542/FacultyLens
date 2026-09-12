"""Question type, difficulty, Bloom level and topic detection against expert labels."""

from __future__ import annotations

from collections import Counter, defaultdict
from typing import Any, Dict, List, Optional, Tuple

from app.services.question_analyzer import QuestionAnalyzer
from evaluation import metrics as M
from evaluation.common import ComponentResult, finalize, timed, truncate
from evaluation.components import Context

TYPES = ["MCQ", "SHORT_ANSWER", "DESCRIPTIVE", "PROBLEM_SOLVING", "TRUE_FALSE", "CONCEPTUAL", "ANALYTICAL"]
DIFFICULTIES = ["EASY", "MEDIUM", "HARD"]
BLOOM = ["REMEMBER", "UNDERSTAND", "APPLY", "ANALYZE", "EVALUATE", "CREATE"]


def _macro_f1(items: List[Tuple[str, str]]) -> Optional[float]:
    return M.classification_report([e for e, _ in items], [p for _, p in items])["macro_f1"]


def _accuracy(items: List[bool]) -> Optional[float]:
    return sum(1 for i in items if i) / len(items) if items else None


def analyze_all(ctx: Context, questions: List[Dict[str, Any]]) -> Tuple[Dict[str, Dict[str, Any]], List[float], List[Dict[str, Any]]]:
    """Run the production QuestionAnalyzer once per question (course topics supplied as in production)."""
    analyzer = QuestionAnalyzer(hf_service=ctx.hf)
    outputs: Dict[str, Dict[str, Any]] = {}
    latencies: List[float] = []
    failures: List[Dict[str, Any]] = []
    for q in questions:
        topics = ctx.catalogue.topics(q["course"])
        try:
            out, ms = timed(analyzer.analyze_single, q["question"], course_topics=topics)
            outputs[q["id"]] = out
            latencies.append(ms)
        except Exception as exc:  # inference failure is a measured outcome, not a crash
            failures.append({"id": q["id"], "input": truncate(q["question"]), "error_type": "MODEL_FAILURE", "note": type(exc).__name__})
    return outputs, latencies, failures


def evaluate(ctx: Context) -> List[ComponentResult]:
    questions = ctx.catalogue.select(ctx.split)
    outputs, latencies, failures = analyze_all(ctx, questions)
    latency = M.latency_summary(latencies)
    results = [
        _classification(ctx, questions, outputs, failures, latency, "QUESTION_CLASSIFICATION", "expected_type", lambda o: o["classification"]["question_type"], TYPES, "WRONG_CLASSIFICATION", confidence=lambda o: o["classification"].get("confidence")),
        _classification(ctx, questions, outputs, failures, latency, "DIFFICULTY_CLASSIFICATION", "expected_difficulty", lambda o: o["difficulty"]["level"], DIFFICULTIES, "WRONG_DIFFICULTY", ordinal=DIFFICULTIES),
        _classification(ctx, questions, outputs, failures, latency, "BLOOM_CLASSIFICATION", "expected_cognitive_level", lambda o: o["cognitive_level"]["level"], BLOOM, "WRONG_BLOOM_LEVEL", ordinal=BLOOM, secondary_key="secondary_cognitive_level"),
        _topics(ctx, questions, outputs, failures, latency),
    ]
    return results


def _classification(ctx: Context, questions, outputs, failures, latency, component: str, expected_key: str, getter, labels, error_type: str,
                    confidence=None, ordinal: Optional[List[str]] = None, secondary_key: Optional[str] = None) -> ComponentResult:
    res = ComponentResult(component=component, split=ctx.split)
    pairs: List[Tuple[str, str]] = []
    lenient_hits: List[bool] = []
    confs: List[float] = []
    corrects: List[bool] = []
    by_course: Dict[str, List[bool]] = defaultdict(list)
    by_label_conf: Dict[str, List[bool]] = defaultdict(list)
    transitions: Counter = Counter()
    ordinal_delta: List[int] = []
    for q in questions:
        out = outputs.get(q["id"])
        if out is None:
            continue
        expected = q[expected_key]
        predicted = getter(out)
        correct = predicted == expected
        secondary = q.get(secondary_key) if secondary_key else None
        lenient = correct or (secondary is not None and predicted == secondary)
        pairs.append((expected, predicted))
        lenient_hits.append(lenient)
        corrects.append(correct)
        by_course[q["course"]].append(correct)
        by_label_conf[q.get("label_confidence", "HIGH")].append(correct)
        if confidence is not None and confidence(out) is not None:
            confs.append(float(confidence(out)))
        if ordinal and expected in ordinal and predicted in ordinal:
            ordinal_delta.append(ordinal.index(predicted) - ordinal.index(expected))
        if not correct:
            transitions[f"{expected}->{predicted}"] += 1
            res.errors.append({
                "id": q["id"], "course": q["course"], "input": truncate(q["question"]), "expected": expected, "actual": predicted,
                "secondary_expected": secondary, "counts_as_error_lenient": not lenient, "label_confidence": q.get("label_confidence"),
                "error_type": error_type, "confidence": confidence(out) if confidence else None,
            })
        ctx.record(component, {"id": q["id"], "expected": expected, "predicted": predicted, "correct": correct, "lenient_correct": lenient,
                               "confidence": confidence(out) if confidence else None, "label_confidence": q.get("label_confidence")})

    res.sample_size = len(pairs)
    res.failure_count = len(failures)
    res.latency = latency
    report = M.classification_report([e for e, _ in pairs], [p for _, p in pairs], labels)
    res.metrics = {k: report[k] for k in ("accuracy", "macro_precision", "macro_recall", "macro_f1", "weighted_f1")}
    res.metrics["lenient_accuracy"] = _accuracy(lenient_hits) if secondary_key else None
    res.metrics["accuracy_ci95"] = M.wilson_interval(sum(corrects), len(corrects))
    res.metrics["accuracy_high_confidence_labels"] = _accuracy(by_label_conf.get("HIGH", []))
    res.metrics["accuracy_medium_low_confidence_labels"] = _accuracy(by_label_conf.get("MEDIUM", []) + by_label_conf.get("LOW", []))
    if ordinal_delta:
        res.metrics["mean_ordinal_shift"] = round(sum(ordinal_delta) / len(ordinal_delta), 4)
        res.metrics["over_classified"] = sum(1 for d in ordinal_delta if d > 0)
        res.metrics["under_classified"] = sum(1 for d in ordinal_delta if d < 0)
    res.structured = {
        "confusion_matrix": report["confusion_matrix"], "per_class": report["per_class"],
        "top_confusions": [{"transition": k, "count": v} for k, v in transitions.most_common(8)],
        "accuracy_by_course": {c: round(_accuracy(v), 4) for c, v in sorted(by_course.items())},
        "label_distribution": dict(Counter(e for e, _ in pairs)),
    }
    if confs and len(confs) == len(corrects):
        res.structured["calibration"] = M.calibration_table(confs, corrects, bins=5)
        res.metrics["ece"] = res.structured["calibration"]["ece"]
    else:
        res.metrics["ece"] = None
        res.notes.append("Confidence: Not available — this engine does not emit a calibrated confidence for this label.")
    ci = M.bootstrap_ci(pairs, _macro_f1, seed=ctx.seed)
    res.notes.append("Rule-based classifier (facultylens-question-analyzer 1.0.0); labels are single-annotator, see labels/LABELING_PROTOCOL.md.")
    if secondary_key:
        res.notes.append("lenient_accuracy accepts the annotator's secondary Bloom label; the gap to accuracy approximates human ambiguity, not model error.")
    return finalize(res, ctx.gates, ci)


def _topics(ctx: Context, questions, outputs, failures, latency) -> ComponentResult:
    res = ComponentResult(component="TOPIC_DETECTION", split=ctx.split)
    top1_hits: List[bool] = []
    top3_hits: List[bool] = []
    expected_sets, predicted_sets = [], []
    confs, corrects = [], []
    universe = 0
    for q in questions:
        out = outputs.get(q["id"])
        if out is None:
            continue
        acceptable = {q["expected_topic"], *q.get("acceptable_topics", [])}
        detected = [t["name"] for t in out["topics"]]
        top1 = detected[0] if detected else None
        hit1 = top1 in acceptable
        hit3 = any(t in acceptable for t in detected[:3])
        top1_hits.append(hit1)
        top3_hits.append(hit3)
        expected_sets.append(acceptable)
        predicted_sets.append(set(detected[:3]))
        universe = max(universe, len(ctx.catalogue.topics(q["course"])))
        if out["topics"] and out["topics"][0].get("confidence") is not None:
            confs.append(float(out["topics"][0]["confidence"]))
            corrects.append(hit1)
        if not hit1:
            res.errors.append({"id": q["id"], "course": q["course"], "input": truncate(q["question"]), "expected": q["expected_topic"], "acceptable": sorted(acceptable), "actual": top1,
                               "actual_top3": detected[:3], "error_type": "WRONG_TOPIC", "confidence": out["topics"][0].get("confidence") if out["topics"] else None, "label_confidence": q.get("label_confidence")})
        ctx.record("TOPIC_DETECTION", {"id": q["id"], "expected": q["expected_topic"], "acceptable": sorted(acceptable), "predicted": detected[:3], "top1_correct": hit1, "top3_correct": hit3})
    res.sample_size = len(top1_hits)
    res.failure_count = len(failures)
    res.latency = latency
    ml = M.multilabel_report(expected_sets, predicted_sets, universe_size=universe or None)
    res.metrics = {"top1_accuracy": _accuracy(top1_hits), "top3_accuracy": _accuracy(top3_hits), "top1_accuracy_ci95": M.wilson_interval(sum(top1_hits), len(top1_hits)), **ml}
    if confs:
        res.structured["calibration"] = M.calibration_table(confs, corrects, bins=5)
        res.metrics["ece"] = res.structured["calibration"]["ece"]
    res.notes.append("Topic detection = MiniLM cosine between question and the course's syllabus topics (threshold 0.25, fallback 0.20, top-3). Multi-label metrics treat the annotator's acceptable set as ground truth.")
    ci = M.wilson_interval(sum(top1_hits), len(top1_hits))
    return finalize(res, ctx.gates, ci)
