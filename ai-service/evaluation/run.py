"""STEP 44 evaluation runner.

    cd ai-service
    python -m evaluation.run                       # full suite on the TEST split -> results/latest
    python -m evaluation.run --components similarity,rag
    python -m evaluation.run --split ALL --out results/all_splits
    python -m evaluation.run --baseline results/baseline   # regression comparison (default when baseline exists)
    python -m evaluation.run --save-baseline               # promote this run to results/baseline

Everything the run depends on (models, prompt versions/hashes, thresholds, dataset versions,
seed, software versions) is written to results/<out>/provenance.json so the run can be reproduced.
"""

from __future__ import annotations

import argparse
import hashlib
import sys
import time
from pathlib import Path
from typing import Any, Dict, List, Optional

from evaluation.common import ComponentResult, PREDICTIONS_DIR, PROMPTS_DIR, RESULTS_DIR, build_provenance, dump_json, load_gates, load_json, new_run_id, Catalogue, utc_now
from evaluation.components import Context
from evaluation.human_review import summarize_reviews
from evaluation.taxonomy import DESCRIPTIONS, ERROR_TYPES

COMPONENT_MODULES = {
    "question_analysis": "evaluation.components.question_analysis",
    "lo_alignment": "evaluation.components.lo_alignment",
    "similarity": "evaluation.components.similarity",
    "rag": "evaluation.components.rag",
    "grading": "evaluation.components.grading",
    "rubric": "evaluation.components.rubric",
    "question_generation": "evaluation.components.question_generation",
    "assessment_quality": "evaluation.components.assessment_quality",
    "consistency": "evaluation.components.consistency",
}


def _services():
    from app.config import get_settings
    from app.services.generation_service import get_generation_service
    from app.services.huggingface_service import get_hf_service
    from app.services.text_generation_service import get_text_generation_service

    hf = get_hf_service()
    if not hf.load_model():
        raise SystemExit(f"Embedding model could not be loaded: {hf.load_error}")
    return hf, get_generation_service(), get_text_generation_service(), get_settings()


def snapshot_prompts() -> List[Dict[str, Any]]:
    """Write the prompt texts actually in use to evaluation/prompts/<feature>_<version>.txt and return their hashes."""
    from app.services import prompt_builder, question_generation_prompt

    entries = [
        ("document_chat", prompt_builder.PROMPT_VERSION, prompt_builder.SYSTEM_INSTRUCTIONS),
        ("question_generation", question_generation_prompt.PROMPT_VERSION, question_generation_prompt.SYSTEM_INSTRUCTIONS),
    ]
    out = []
    PROMPTS_DIR.mkdir(parents=True, exist_ok=True)
    for feature, version, text in entries:
        digest = hashlib.sha256(text.encode("utf-8")).hexdigest()
        path = PROMPTS_DIR / f"{feature}_{version}.txt"
        if not path.exists():
            path.write_text(text, encoding="utf-8")
        out.append({"feature": feature, "version": version, "sha256": digest, "file": path.name})
    return out


def run(components: List[str], split: str, out_dir: Path, baseline: Optional[Path], repeats: int, seed: int, verbose: bool) -> Dict[str, Any]:
    hf, generation, text_generation, settings = _services()
    gates = load_gates()
    run_id = new_run_id()
    predictions_dir = PREDICTIONS_DIR / run_id
    ctx = Context(hf=hf, generation=generation, text_generation=text_generation, catalogue=Catalogue(), gates=gates, split=split, seed=seed, repeats=repeats, predictions_dir=predictions_dir, settings=settings, verbose=verbose)
    provenance = build_provenance(hf, generation, seed, split) | {"run_id": run_id, "prompt_snapshots": snapshot_prompts(), "components_requested": components}

    results: List[ComponentResult] = []
    timings: Dict[str, float] = {}
    for name in components:
        module = __import__(COMPONENT_MODULES[name], fromlist=["evaluate"])
        t0 = time.perf_counter()
        print(f"[eval] {name} ...", flush=True)
        try:
            results.extend(module.evaluate(ctx))
        except Exception as exc:  # a crashing component is a MODEL_FAILURE for the whole component, not a silent skip
            failed = ComponentResult(component=name.upper(), status="NOT_EVALUATED", notes=[f"Component crashed: {type(exc).__name__}: {exc}"])
            failed.errors.append({"error_type": "MODEL_FAILURE", "note": f"{type(exc).__name__}: {exc}"})
            results.append(failed)
            if verbose:
                import traceback

                traceback.print_exc()
        timings[name] = round(time.perf_counter() - t0, 2)

    covered = {r.component for r in results}
    for component in gates["components"]:
        if component not in covered:
            results.append(ComponentResult(component=component, status="NOT_EVALUATED", notes=["Not part of this run."]))

    out_dir.mkdir(parents=True, exist_ok=True)
    for r in results:
        dump_json(out_dir / f"{r.component}.json", r.to_dict())
    prediction_counts = ctx.flush_predictions()
    summary = {
        "run_id": run_id, "evaluated_at": utc_now(), "split": split, "seconds_per_component": timings,
        "components": [{"component": r.component, "status": r.status, "sample_size": r.sample_size, "headline": r.headline, "failure_rate": r.failure_rate, "latency_ms": r.latency,
                        "error_breakdown": r.to_dict()["error_breakdown"], "human_review_status": (r.human_review or {}).get("status")} for r in results],
        "error_taxonomy": {k: DESCRIPTIONS[k] for k in ERROR_TYPES},
        "predictions": {"dir": str(predictions_dir.relative_to(PREDICTIONS_DIR.parent)), "counts": prediction_counts},
        "human_reviews": {c: summarize_reviews(c) for c in gates.get("human_review_components", [])},
        "faculty_acceptance_note": "Faculty acceptance / override signals live in the Laravel STEP 35 dashboard (production data) and are deliberately not mixed into these accuracy metrics.",
    }
    dump_json(out_dir / "provenance.json", provenance)
    dump_json(out_dir / "summary.json", summary)
    regression = compare(baseline, out_dir, gates) if baseline and baseline.exists() else {"status": "NO_BASELINE", "note": "No baseline directory found; run with --save-baseline to create one."}
    dump_json(out_dir / "regression.json", regression)
    _print_table(summary, regression)
    return {"summary": summary, "regression": regression, "provenance": provenance}


def compare(baseline: Path, current: Path, gates: Dict[str, Any]) -> Dict[str, Any]:
    """Headline metric per component: baseline vs current, with the component's regression tolerance."""
    rows = []
    worst = "PASSED"
    for component, spec in gates["components"].items():
        b_path, c_path = baseline / f"{component}.json", current / f"{component}.json"
        if not b_path.exists() or not c_path.exists():
            rows.append({"component": component, "status": "NOT_COMPARED"})
            continue
        b, c = load_json(b_path), load_json(c_path)
        bv, cv = (b.get("headline") or {}).get("value"), (c.get("headline") or {}).get("value")
        if bv is None or cv is None:
            rows.append({"component": component, "status": "NOT_COMPARED", "baseline": bv, "current": cv})
            continue
        higher = spec.get("higher_is_better", True)
        delta = cv - bv
        rel = (delta / abs(bv)) if bv else (0.0 if delta == 0 else float("inf"))
        regressed = (rel < -spec["regression_tolerance"]) if higher else (rel > spec["regression_tolerance"])
        improved = (delta > 0) if higher else (delta < 0)
        status = "REGRESSION" if regressed else ("IMPROVED" if improved and abs(rel) > 0.01 else "STABLE")
        if status == "REGRESSION":
            worst = "FAILED"
        rows.append({"component": component, "metric": spec["headline"], "baseline": bv, "current": cv, "delta": round(delta, 4), "relative_change": round(rel, 4) if rel != float("inf") else None,
                     "tolerance": spec["regression_tolerance"], "status": status, "baseline_status": b.get("status"), "current_status": c.get("status")})
    b_prov = load_json(baseline / "provenance.json") if (baseline / "provenance.json").exists() else {}
    return {"status": worst, "baseline_run": b_prov.get("run_id"), "baseline_commit": b_prov.get("git_commit"), "baseline_evaluated_at": b_prov.get("evaluated_at"), "rows": rows}


def _print_table(summary: Dict[str, Any], regression: Dict[str, Any]) -> None:
    print("\n" + "=" * 118)
    print(f"FacultyLens AI accuracy evaluation - run {summary['run_id']} (split {summary['split']})")
    print("=" * 118)
    print(f"{'Component':<28}{'Status':<20}{'n':>5}  {'Headline':<30}{'Value':>8}  {'95% CI':<18}{'Fail%':>6}  {'p95 ms':>8}")
    print("-" * 118)
    reg = {r["component"]: r for r in regression.get("rows", [])}
    for c in summary["components"]:
        h = c.get("headline") or {}
        ci = h.get("ci95")
        ci_s = f"[{ci[0]:.3f}, {ci[1]:.3f}]" if ci else "-"
        val = f"{h['value']:.3f}" if isinstance(h.get("value"), (int, float)) else "-"
        fail = f"{(c['failure_rate'] or 0) * 100:.1f}" if c.get("sample_size") else "-"
        p95 = (c.get("latency_ms") or {}).get("p95_ms")
        r = reg.get(c["component"], {})
        tag = f"  {r['status']}" if r.get("status") in ("REGRESSION", "IMPROVED") else ""
        print(f"{c['component']:<28}{c['status']:<20}{c['sample_size']:>5}  {str(h.get('metric') or '-'):<30}{val:>8}  {ci_s:<18}{fail:>6}  {p95 if p95 is not None else '-':>8}{tag}")
    print("-" * 118)
    print(f"Regression vs baseline: {regression.get('status')}" + (f" (baseline run {regression.get('baseline_run')})" if regression.get("baseline_run") else ""))
    print("=" * 118 + "\n")


def main(argv: Optional[List[str]] = None) -> int:
    parser = argparse.ArgumentParser(description="FacultyLens AI accuracy & evaluation validation (STEP 44)")
    parser.add_argument("--components", default="all", help="comma-separated subset of: " + ",".join(COMPONENT_MODULES))
    parser.add_argument("--split", default="TEST", choices=["TEST", "DEV", "VALIDATION", "ALL"])
    parser.add_argument("--out", default=str(RESULTS_DIR / "latest"))
    parser.add_argument("--baseline", default=str(RESULTS_DIR / "baseline"))
    parser.add_argument("--save-baseline", action="store_true", help="copy this run's results to the baseline directory afterwards")
    parser.add_argument("--repeats", type=int, default=3)
    parser.add_argument("--seed", type=int, default=42)
    parser.add_argument("--strict", action="store_true", help="exit 1 when a regression gate fails")
    parser.add_argument("--verbose", action="store_true")
    args = parser.parse_args(argv)

    components = list(COMPONENT_MODULES) if args.components == "all" else [c.strip() for c in args.components.split(",") if c.strip()]
    unknown = [c for c in components if c not in COMPONENT_MODULES]
    if unknown:
        parser.error(f"unknown components: {unknown}")
    out_dir = Path(args.out)
    outcome = run(components, args.split, out_dir, Path(args.baseline), args.repeats, args.seed, args.verbose)
    if args.save_baseline:
        import shutil

        base = Path(args.baseline)
        base.mkdir(parents=True, exist_ok=True)
        for f in out_dir.glob("*.json"):
            shutil.copy2(f, base / f.name)
        print(f"[eval] baseline updated at {base}")
    if args.strict and outcome["regression"].get("status") == "FAILED":
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
