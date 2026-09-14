"""STEP 46: run the AI safety dataset cases and write a results file.

    python -m evaluation.safety_run                # → evaluation/results/safety/latest.json
    python -m evaluation.safety_run --out path.json
    python -m evaluation.safety_run --strict       # exit 1 when any CRITICAL/HIGH case fails

Metrics are computed only from cases that actually executed; categories without executed cases
are reported as NOT_TESTED, never as 0 %.
"""

from __future__ import annotations

import argparse
import sys
from dataclasses import asdict
from pathlib import Path

from evaluation.common import dump_json, utc_now
from evaluation import safety_harness as harness

RESULTS_DIR = Path(__file__).resolve().parent / "results" / "safety"


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description="FacultyLens AI safety case runner")
    parser.add_argument("--out", default=str(RESULTS_DIR / "latest.json"))
    parser.add_argument("--strict", action="store_true", help="exit 1 if any CRITICAL or HIGH case fails")
    args = parser.parse_args(argv)

    cases = harness.load_cases()
    schema_errors = {c.get("id", "?"): e for c in cases if (e := harness.validate_case_schema(c))}
    if schema_errors:
        print("Dataset schema errors:", schema_errors, file=sys.stderr)
        return 2

    results = [harness.execute(c) for c in cases]
    metrics = harness.compute_metrics(results)
    severity = harness.severity_summary(results)
    failed = [r for r in results if not r.passed]

    payload = {
        "run_at": utc_now(),
        "dataset_dir": str(harness.SAFETY_DATASET_DIR),
        "total_cases": len(results),
        "passed": len(results) - len(failed),
        "failed": len(failed),
        "severity": severity,
        "metrics": metrics,
        "failures": [
            {"case_id": r.case_id, "category": r.category, "severity": r.severity, "surface": r.surface, "failures": r.failures}
            for r in failed
        ],
        "cases": [
            {k: v for k, v in asdict(r).items() if k not in ("body", "prompt")} for r in results
        ],
    }
    dump_json(Path(args.out), payload)

    print(f"Safety cases: {payload['passed']}/{payload['total_cases']} passed")
    for name, m in metrics.items():
        if name.startswith("_"):
            continue
        rate = "NOT_TESTED" if m["rate"] is None else f"{m['rate']:.1%} ({m['numerator']}/{m['denominator']})"
        print(f"  {name:32s} {rate}")
    for r in failed:
        print(f"  FAIL {r.case_id} [{r.severity}] {r.failures}")

    if args.strict and any(r.severity in ("CRITICAL", "HIGH") for r in failed):
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
