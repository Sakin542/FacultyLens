"""Standardised AI error taxonomy (STEP 44 §29).

Every example-level failure recorded by the harness carries exactly one of these codes so error
analysis can be aggregated across components and compared between runs. STEP 35 (Laravel)
codes are mapped onto the same vocabulary via ``from_step35``.
"""

from __future__ import annotations

from typing import Dict, Optional

ERROR_TYPES = [
    "WRONG_CLASSIFICATION",
    "WRONG_DIFFICULTY",
    "WRONG_BLOOM_LEVEL",
    "WRONG_TOPIC",
    "WRONG_LO_ALIGNMENT",
    "FALSE_SIMILARITY",
    "MISSED_SIMILARITY",
    "UNSUPPORTED_CLAIM",
    "HALLUCINATION",
    "WRONG_CITATION",
    "WRONG_RUBRIC",
    "CONSTRAINT_VIOLATION",
    "GRADING_ERROR",
    "INCONSISTENT_OUTPUT",
    "TIMEOUT",
    "MODEL_FAILURE",
]

# Human-readable description used by the report generator.
DESCRIPTIONS: Dict[str, str] = {
    "WRONG_CLASSIFICATION": "Question type differs from the expert label.",
    "WRONG_DIFFICULTY": "Difficulty band differs from the expert label.",
    "WRONG_BLOOM_LEVEL": "Bloom level differs from the expert label (and from any secondary label).",
    "WRONG_TOPIC": "Top detected topic is not in the expert's acceptable topic set.",
    "WRONG_LO_ALIGNMENT": "Alignment band (STRONG/WEAK/NOT_ALIGNED) or top-ranked LO differs from the expert label.",
    "FALSE_SIMILARITY": "Pair rated more similar than the expert label (e.g. NOT_SIMILAR predicted as HIGHLY_SIMILAR).",
    "MISSED_SIMILARITY": "Pair rated less similar than the expert label (e.g. a duplicate not flagged).",
    "UNSUPPORTED_CLAIM": "Answer content is not supported by the cited/retrieved evidence, or an answer was given where none exists.",
    "HALLUCINATION": "Generated content introduces terms or facts absent from the grounding documents.",
    "WRONG_CITATION": "Citation is missing, points to the wrong chunk/document, or does not support the claim.",
    "WRONG_RUBRIC": "Rubric fails structural validity (marks sum, empty/duplicate criteria, criterion count).",
    "CONSTRAINT_VIOLATION": "Generated question violates a requested constraint (type, difficulty, Bloom, marks, topic, LO, language, similarity).",
    "GRADING_ERROR": "Absolute difference between AI and faculty marks exceeds the large-error threshold.",
    "INCONSISTENT_OUTPUT": "Repeated identical inputs produced different labels/scores beyond tolerance.",
    "TIMEOUT": "Inference exceeded the per-example time budget.",
    "MODEL_FAILURE": "Inference raised an exception or returned an invalid payload.",
}

_STEP35_MAP: Dict[str, str] = {
    "WRONG_CLASS": "WRONG_CLASSIFICATION",
    "WRONG_ALIGNMENT": "WRONG_LO_ALIGNMENT",
    "FALSE_DUPLICATE": "FALSE_SIMILARITY",
    "MISSED_DUPLICATE": "MISSED_SIMILARITY",
    "WRONG_DIFFICULTY": "WRONG_DIFFICULTY",
    "WRONG_BLOOM_LEVEL": "WRONG_BLOOM_LEVEL",
    "UNSUPPORTED_CLAIM": "UNSUPPORTED_CLAIM",
    "WRONG_CITATION": "WRONG_CITATION",
    "MISSED_REFUSAL": "UNSUPPORTED_CLAIM",
    "INJECTION_LEAK": "UNSUPPORTED_CLAIM",
    "CONSTRAINT_VIOLATION": "CONSTRAINT_VIOLATION",
    "MARKS_MISMATCH": "WRONG_RUBRIC",
    "LARGE_ERROR": "GRADING_ERROR",
    "INFERENCE_ERROR": "MODEL_FAILURE",
    "OTHER": "UNSUPPORTED_CLAIM",
}


def from_step35(code: Optional[str]) -> Optional[str]:
    if not code:
        return None
    return _STEP35_MAP.get(code, code if code in ERROR_TYPES else "MODEL_FAILURE")


def assert_known(code: str) -> str:
    if code not in ERROR_TYPES:
        raise ValueError(f"Unknown error type: {code}")
    return code
