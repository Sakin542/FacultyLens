"""Shared infrastructure for the STEP 44 evaluation harness.

Datasets live in ``evaluation/datasets``, run outputs in ``evaluation/results/<run>`` and
example-level predictions in ``evaluation/predictions/<run>``. Nothing here touches production
thresholds or models; the harness *reads* the same configuration the service uses.
"""

from __future__ import annotations

import json
import os
import platform
import subprocess
import sys
import time
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable, Dict, Iterable, List, Optional, Tuple

EVAL_DIR = Path(__file__).resolve().parent
AI_SERVICE_DIR = EVAL_DIR.parent
DATASETS_DIR = EVAL_DIR / "datasets"
LABELS_DIR = EVAL_DIR / "labels"
RESULTS_DIR = EVAL_DIR / "results"
PREDICTIONS_DIR = EVAL_DIR / "predictions"
PROMPTS_DIR = EVAL_DIR / "prompts"

DATASET_FILES = {
    "courses": "courses_v1.json",
    "questions": "questions_v1.json",
    "lo_pairs": "lo_alignment_pairs_v1.json",
    "similarity_pairs": "similarity_pairs_v1.json",
    "retrieval": "retrieval_queries_v1.json",
    "rag": "rag_corpus_v1.json",
    "grading": "grading_benchmark_v1.json",
    "assessment_cases": "assessment_cases_v1.json",
    "generation": "generation_requests_v1.json",
}

STATUSES = ["EXCELLENT", "GOOD", "ACCEPTABLE", "NEEDS_IMPROVEMENT", "INSUFFICIENT_DATA", "NOT_EVALUATED"]


def load_json(path: Path) -> Any:
    with open(path, encoding="utf-8") as fh:
        return json.load(fh)


def dump_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with open(path, "w", encoding="utf-8") as fh:
        json.dump(payload, fh, indent=2, ensure_ascii=False, default=str)
        fh.write("\n")


def load_dataset(key: str) -> Dict[str, Any]:
    return load_json(DATASETS_DIR / DATASET_FILES[key])


def load_gates() -> Dict[str, Any]:
    return load_json(EVAL_DIR / "gates.json")


def utc_now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def new_run_id() -> str:
    return datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")


def timed(fn: Callable[..., Any], *args: Any, **kwargs: Any) -> Tuple[Any, float]:
    t0 = time.perf_counter()
    out = fn(*args, **kwargs)
    return out, (time.perf_counter() - t0) * 1000.0


# ------------------------------------------------------------------ dataset helpers


class Catalogue:
    """Resolves question ids, course topics and LO codes from the datasets."""

    def __init__(self) -> None:
        self.courses = load_dataset("courses")["courses"]
        qs = load_dataset("questions")
        self.questions_meta = {k: v for k, v in qs.items() if k != "questions"}
        self.questions: Dict[str, Dict[str, Any]] = {q["id"]: q for q in qs["questions"]}

    def question(self, qid: str) -> Dict[str, Any]:
        return self.questions[qid]

    def topics(self, course: str) -> List[str]:
        return list(self.courses[course]["topics"])

    def los(self, course: str) -> Dict[str, str]:
        return dict(self.courses[course]["learning_outcomes"])

    def lo_text(self, ref: str) -> Tuple[str, str, str]:
        """'CS301:LO2' -> (course, code, description)."""
        course, code = ref.split(":", 1)
        return course, code, self.courses[course]["learning_outcomes"][code]

    def select(self, split: str) -> List[Dict[str, Any]]:
        if split.upper() == "ALL":
            return list(self.questions.values())
        return [q for q in self.questions.values() if q["split"] == split.upper()]


# ------------------------------------------------------------------ provenance


def _git_commit() -> Optional[str]:
    try:
        out = subprocess.run(["git", "rev-parse", "--short", "HEAD"], cwd=AI_SERVICE_DIR, capture_output=True, text=True, timeout=5, check=False)  # noqa: S603,S607
        return out.stdout.strip() or None
    except Exception:
        return None


def _lib_version(name: str) -> Optional[str]:
    try:
        module = __import__(name)
        return getattr(module, "__version__", None)
    except Exception:
        return None


def build_provenance(hf_service: Any, generation_service: Any, seed: int, split: str) -> Dict[str, Any]:
    """Model / prompt / threshold / dataset / software versions actually used by this run."""
    from app.services.evaluation_inventory import model_inventory

    inventory = model_inventory(hf_service, generation_service)
    datasets = {}
    for key, fname in DATASET_FILES.items():
        data = load_json(DATASETS_DIR / fname)
        datasets[key] = {"file": fname, "version": data.get("version"), "created": data.get("created")}
    return {
        "evaluated_at": utc_now(),
        "split": split,
        "random_seed": seed,
        "git_commit": _git_commit(),
        "embedding_model": inventory["embedding_model"],
        "generation_model": inventory["generation_model"],
        "engines": inventory["engines"],
        "prompt_versions": inventory["prompt_versions"],
        "thresholds": inventory["thresholds"] | {
            "alignment": {"strong": 0.70, "weak": 0.50},
            "topic_detection": {"threshold": 0.25, "fallback": 0.20, "top_k": 3},
        },
        "generation_settings": {"do_sample": False, "note": "Generation models are called with greedy decoding when configured; none configured in this run unless generation_model.configured is true."},
        "software": {
            "python": sys.version.split()[0],
            "platform": platform.platform(),
            "sentence_transformers": _lib_version("sentence_transformers"),
            "transformers": _lib_version("transformers"),
            "torch": _lib_version("torch"),
            "numpy": _lib_version("numpy"),
        },
        "datasets": datasets,
    }


# ------------------------------------------------------------------ results


@dataclass
class ComponentResult:
    component: str
    sample_size: int = 0
    split: str = "TEST"
    metrics: Dict[str, Any] = field(default_factory=dict)
    structured: Dict[str, Any] = field(default_factory=dict)
    latency: Dict[str, Any] = field(default_factory=dict)
    failure_count: int = 0
    errors: List[Dict[str, Any]] = field(default_factory=list)
    notes: List[str] = field(default_factory=list)
    status: str = "NOT_EVALUATED"
    headline: Dict[str, Any] = field(default_factory=dict)
    human_review: Dict[str, Any] = field(default_factory=dict)

    @property
    def failure_rate(self) -> Optional[float]:
        return round(self.failure_count / self.sample_size, 4) if self.sample_size else None

    def to_dict(self) -> Dict[str, Any]:
        return {
            "component": self.component, "status": self.status, "split": self.split, "sample_size": self.sample_size,
            "headline": self.headline, "metrics": self.metrics, "structured": self.structured, "latency_ms": self.latency,
            "failure_count": self.failure_count, "failure_rate": self.failure_rate,
            "error_breakdown": _count(e.get("error_type") for e in self.errors), "errors": self.errors,
            "human_review": self.human_review, "notes": self.notes,
        }


def _count(values: Iterable[Optional[str]]) -> Dict[str, int]:
    out: Dict[str, int] = {}
    for v in values:
        if v:
            out[v] = out.get(v, 0) + 1
    return dict(sorted(out.items(), key=lambda kv: -kv[1]))


def classify(component: str, value: Optional[float], n: int, gates: Dict[str, Any]) -> str:
    """Map a headline metric to EXCELLENT/GOOD/ACCEPTABLE/NEEDS_IMPROVEMENT/INSUFFICIENT_DATA/NOT_EVALUATED."""
    spec = gates["components"].get(component)
    if spec is None or value is None:
        return "NOT_EVALUATED"
    if n < int(spec.get("min_n", 1)):
        return "INSUFFICIENT_DATA"
    bands = spec["bands"]
    if spec.get("higher_is_better", True):
        for label in ("EXCELLENT", "GOOD", "ACCEPTABLE"):
            if value >= bands[label]:
                return label
        return "NEEDS_IMPROVEMENT"
    for label in ("EXCELLENT", "GOOD", "ACCEPTABLE"):
        if value <= bands[label]:
            return label
    return "NEEDS_IMPROVEMENT"


def finalize(result: ComponentResult, gates: Dict[str, Any], ci: Optional[Tuple[float, float]] = None) -> ComponentResult:
    spec = gates["components"].get(result.component, {})
    metric = spec.get("headline")
    value = result.metrics.get(metric) if metric else None
    result.headline = {"metric": metric, "value": value, "ci95": list(ci) if ci else None, "higher_is_better": spec.get("higher_is_better", True), "sample_size": result.sample_size}
    result.status = classify(result.component, value, result.sample_size, gates)
    return result


def truncate(text: Any, limit: int = 160) -> str:
    s = str(text)
    return s if len(s) <= limit else s[: limit - 1] + "…"


def env_flag(name: str, default: bool = False) -> bool:
    raw = os.environ.get(name)
    if raw is None:
        return default
    return raw.strip().lower() in ("1", "true", "yes", "on")
