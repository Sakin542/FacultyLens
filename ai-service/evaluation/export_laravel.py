"""Export the STEP 44 datasets in the STEP 35 (Laravel) AI-evaluation dataset format.

    python -m evaluation.export_laravel [--out results/laravel_import]

One JSON file per Laravel task; each is a ready payload for ``POST /api/ai/evaluation/datasets`` or for
``php artisan ai-evaluation:import <file>``. Tasks without a STEP 35 evaluator (topic detection, retrieval,
assessment quality, recommendations, consistency) are Python-harness only and are not exported.
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path
from typing import Any, Dict, List

from evaluation.common import Catalogue, RESULTS_DIR, dump_json, load_dataset

SOURCE = "CURATED_DATASET"


def _dataset(name: str, task: str, version: str, description: str, examples: List[Dict[str, Any]], split: str = "TEST") -> Dict[str, Any]:
    return {"name": name, "task": task, "version": version, "source": SOURCE, "split": split, "description": description, "examples": examples}


def export_all(cat: Catalogue) -> Dict[str, Dict[str, Any]]:
    qversion = cat.questions_meta.get("version", "v1")
    out: Dict[str, Dict[str, Any]] = {}
    questions = list(cat.questions.values())
    for task, key in (("QUESTION_CLASSIFICATION", "expected_type"), ("DIFFICULTY_CLASSIFICATION", "expected_difficulty"), ("BLOOM_CLASSIFICATION", "expected_cognitive_level")):
        out[task] = _dataset(f"STEP44 benchmark – {task.replace('_', ' ').title()} ({qversion})", task, qversion,
                             "STEP 44 ground-truth question set (labels/LABELING_PROTOCOL.md). Single-annotator labels; 144 questions across 8 courses.",
                             [{"input_data": {"question": q["question"]}, "expected_output": {key: q[key]},
                               "metadata": {"benchmark_id": q["id"], "course": q["course"], "marks": q["marks"], "label_confidence": q.get("label_confidence"), "secondary_cognitive_level": q.get("secondary_cognitive_level")},
                               "split": q["split"]} for q in questions], split="TEST")
    pairs = load_dataset("lo_pairs")
    out["LO_ALIGNMENT"] = _dataset(f"STEP44 benchmark – LO Alignment pairs ({pairs['version']})", "LO_ALIGNMENT", pairs["version"], "86 expert-labelled (question, learning outcome) pairs: STRONG / WEAK / NOT_ALIGNED.",
                                   [{"input_data": {"question": cat.question(p["question_id"])["question"], "learning_outcome": cat.lo_text(p["lo"])[2]}, "expected_output": {"expected_alignment": p["expected_alignment"]},
                                     "metadata": {"benchmark_id": p["id"], "question_id": p["question_id"], "lo": p["lo"], "label_confidence": p.get("label_confidence")}} for p in pairs["pairs"]])
    sim = load_dataset("similarity_pairs")
    out["SIMILARITY"] = _dataset(f"STEP44 benchmark – Similarity pairs ({sim['version']})", "SIMILARITY", sim["version"], "60 expert-labelled question pairs across four similarity bands, including hard negatives.",
                                 [{"input_data": {"question_a": p["question_a"], "question_b": p["question_b"]}, "expected_output": {"expected_relationship": p["expected_relationship"]},
                                   "metadata": {"benchmark_id": p["id"], "expert_score": p["expert_score"], "hard_negative": p.get("hard_negative")}} for p in sim["pairs"]])
    grading = load_dataset("grading")
    examples = []
    for item in grading["items"]:
        for ans in item["answers"]:
            examples.append({"input_data": {"answer": ans["text"], "question": item["question"], "max_marks": item["total_marks"], "question_type": item["question_type"],
                                            "rubric": {"total_marks": item["total_marks"], "criteria": [{"id": c["id"], "criterion": c["criterion"], "description": c["description"], "max_marks": c["max_marks"], "expected_indicators": c.get("expected_indicators", [])} for c in item["rubric"]]}},
                             "expected_output": {"faculty_marks": ans["faculty_marks"]},
                             "metadata": {"benchmark_id": ans["id"], "question_type": item["question_type"], "difficulty": item.get("difficulty_level"), "cognitive_level": item.get("cognitive_level"), "course": item["course"], "rationale": ans.get("rationale")}})
    out["GRADING_ASSISTANCE"] = _dataset(f"STEP44 benchmark – Grading ({grading['version']})", "GRADING_ASSISTANCE", grading["version"], "24 synthetic answers to 8 questions, faculty-marked against rubrics with written rationale.", examples)
    rag = load_dataset("rag")
    chunk_docs = {c["chunk_id"]: {"name": d["document_name"], "content": c["content"], "page": c.get("page_number"), "section": c.get("section_title")} for d in rag["documents"] for c in d["chunks"]}
    docs = [{"name": d["document_name"], "content": " ".join(c["content"] for c in d["chunks"]), "page": d["chunks"][0].get("page_number")} for d in rag["documents"]]
    examples = []
    for q in rag["queries"]:
        present = bool(q["answer_present"]) if q.get("answer_present") is not None else False
        expected_source = None
        if q.get("relevant_chunks"):
            top = max(q["relevant_chunks"].items(), key=lambda kv: kv[1])[0]
            expected_source = chunk_docs[int(top)]["name"]
        examples.append({"input_data": {"question": q["query"], "documents": docs}, "expected_output": {"answer_present": present, "expected_source": expected_source, "answer_keywords": q.get("answer_keywords", []), "injection": bool(q.get("injection"))},
                         "metadata": {"benchmark_id": q["id"], "relevant_chunks": q.get("relevant_chunks"), "note": q.get("note")}})
    out["DOCUMENT_CHAT"] = _dataset(f"STEP44 benchmark – Document chat ({rag['version']})", "DOCUMENT_CHAT", rag["version"], "24 queries over 4 synthetic academic documents (20 answerable, 2 unanswerable, 2 prompt-injection). Laravel feeds whole documents; retrieval ranking is measured by the Python harness.", examples)
    gen = load_dataset("generation")
    examples = []
    for spec in gen["question_generation_requests"]:
        if spec.get("document_chunk_ids") or spec.get("existing_question_ids") or spec.get("blueprint"):
            continue  # Laravel evaluator takes plain constraint sets
        course = cat.courses[spec["course"]]
        examples.append({"input_data": {"topic": spec.get("topic"), "question_type": spec.get("question_type"), "difficulty_level": spec.get("difficulty_level"), "cognitive_level": spec.get("cognitive_level"), "marks": spec.get("marks"),
                                        "number_of_questions": spec.get("number_of_questions", 1), "learning_outcome": {"code": spec.get("learning_outcome"), "description": course["learning_outcomes"].get(spec.get("learning_outcome") or "", "")} if spec.get("learning_outcome") else None},
                         "expected_output": {"min_constraint_satisfaction": 0.9}, "metadata": {"benchmark_id": spec["id"], "course": spec["course"]}})
    out["QUESTION_GENERATION"] = _dataset(f"STEP44 benchmark – Question generation ({gen['version']})", "QUESTION_GENERATION", gen["version"], "Plain constraint sets from the STEP 44 generation requests.", examples)
    examples = []
    for qid in gen["rubric_question_ids"]:
        q = cat.question(qid)
        examples.append({"input_data": {"question": q["question"], "total_marks": q["marks"], "difficulty": q["expected_difficulty"], "cognitive_level": q["expected_cognitive_level"], "question_type": q["expected_type"],
                                        "learning_outcome": cat.los(q["course"]).get(q["learning_outcome"]) if q.get("learning_outcome") else None},
                         "expected_output": {}, "metadata": {"benchmark_id": qid, "course": q["course"], "note": "No faculty ratings yet — marks validity only."}})
    out["RUBRIC_GENERATION"] = _dataset(f"STEP44 benchmark – Rubric generation ({gen['version']})", "RUBRIC_GENERATION", gen["version"], "50 rubric requests; structural validity only until faculty ratings are collected.", examples)
    return out


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description="Export STEP 44 datasets as STEP 35 Laravel import payloads")
    parser.add_argument("--out", default=str(RESULTS_DIR / "laravel_import"))
    args = parser.parse_args(argv)
    out_dir = Path(args.out)
    payloads = export_all(Catalogue())
    for task, payload in payloads.items():
        dump_json(out_dir / f"{task}.json", payload)
        print(f"[export] {task}: {len(payload['examples'])} examples -> {out_dir / (task + '.json')}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
