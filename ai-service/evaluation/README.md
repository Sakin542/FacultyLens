# FacultyLens AI Evaluation Harness (STEP 44)

Measures how accurately each AI component reproduces **expert labels** — separately from faculty acceptance
and from any academic decision. Results are persisted as JSON here and (via export/import) in the STEP 35
Laravel tables so they appear on `/ai-evaluation`.

```
evaluation/
├── datasets/      ground-truth datasets (v1.0) — see labels/LABELING_PROTOCOL.md
├── labels/        labelling protocol, faculty review form, human_reviews/<COMPONENT>/*.json (empty → NOT_EVALUATED)
├── prompts/       snapshot of the prompt texts actually in use (hashed in provenance.json)
├── predictions/   example-level predictions per run id
├── results/
│   ├── baseline/  reference run used for regression gates
│   ├── latest/    most recent run: summary.json, provenance.json, regression.json, <COMPONENT>.json
│   └── laravel_import/  STEP 35 payloads produced by export_laravel.py
├── components/    one evaluator per component (all call the production service code in-process)
├── metrics.py     classification / ranking / regression / calibration / CI primitives (None when undefined)
├── taxonomy.py    STEP 44 error taxonomy (+ mapping from STEP 35 codes)
├── gates.json     result bands (EXCELLENT … NEEDS_IMPROVEMENT), min sample sizes, regression tolerances
├── run.py         CLI
└── export_laravel.py
```

## Commands

```bash
cd ai-service
python -m evaluation.run                              # TEST split → results/latest, compared with results/baseline
python -m evaluation.run --components similarity,rag  # subset
python -m evaluation.run --split ALL --out evaluation/results/all
python -m evaluation.run --save-baseline              # promote the run to the regression baseline
python -m evaluation.run --strict                     # exit 1 on a regression gate failure
python -m evaluation.export_laravel                   # STEP 35 payloads
```

From the repository root: `bash scripts/run-ai-evaluation.sh [--laravel] [any run.py flags]` or
`.\scripts\run-ai-evaluation.ps1 [-Laravel] [-Split ALL] [-SaveBaseline] [-Strict]`.

Persist in Laravel (Docker stack up):

```bash
docker compose exec -T app php artisan ai-evaluation:import storage/app/ai-evaluation-benchmark --run --sync --replace
```

## Rules the harness enforces

- Metrics are `None` (never 0) when undefined; a component is `INSUFFICIENT_DATA` below its minimum sample size and
  `NOT_EVALUATED` when it did not run. Human quality is `NOT_EVALUATED` until faculty review forms exist.
- Production thresholds are read, never modified. Threshold sweeps are informational.
- Every run records model, engine and prompt versions/hashes, thresholds, dataset versions, seed, software
  versions and git commit (`provenance.json`) so it can be reproduced.
- Every example-level failure carries one taxonomy code (`WRONG_CLASSIFICATION`, `WRONG_LO_ALIGNMENT`, …,
  `MODEL_FAILURE`).

Report: `docs/AI_ACCURACY_EVALUATION_REPORT.md`.
