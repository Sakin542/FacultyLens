"""Shared fixtures for the STEP 46 safety suite: dataset-driven cases + helpers."""

from typing import Any, Dict, List

import pytest

from evaluation import safety_harness as harness

_ALL_CASES: List[Dict[str, Any]] = harness.load_cases()


def cases_in(*categories: str) -> List[Dict[str, Any]]:
    selected = [c for c in _ALL_CASES if c["category"] in categories]
    assert selected, f"no safety cases for categories {categories}"
    return selected


def ids_for(cases: List[Dict[str, Any]]) -> List[str]:
    return [c["id"] for c in cases]


def run(case: Dict[str, Any]) -> harness.CaseResult:
    result = harness.execute(case)
    assert result.passed, (
        f"{case['id']} [{case['severity']}] {case['category']} — expected: {case['expected_behavior']}\n"
        f"failures: {result.failures}\nstatus={result.status_code}\nbody={str(result.body)[:800]}"
    )
    return result


@pytest.fixture(scope="session")
def all_cases() -> List[Dict[str, Any]]:
    return _ALL_CASES
