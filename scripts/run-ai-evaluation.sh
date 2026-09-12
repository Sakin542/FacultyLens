#!/usr/bin/env bash
# STEP 44 — FacultyLens AI accuracy & evaluation validation.
#
#   bash scripts/run-ai-evaluation.sh                 # full Python harness on the TEST split -> ai-service/evaluation/results/latest
#   bash scripts/run-ai-evaluation.sh --split ALL     # any evaluation.run flag is passed through
#   bash scripts/run-ai-evaluation.sh --laravel       # additionally export + import into the STEP 35 tables (Docker stack required)
#   bash scripts/run-ai-evaluation.sh --save-baseline # promote this run to the regression baseline
#
# Exit code 1 when --strict is passed and a regression gate fails.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
AI="$ROOT/ai-service"
LARAVEL=0
ARGS=()
for a in "$@"; do
  if [[ "$a" == "--laravel" ]]; then LARAVEL=1; else ARGS+=("$a"); fi
done

if [[ -x "$AI/.venv/Scripts/python.exe" ]]; then PY="$AI/.venv/Scripts/python.exe"
elif [[ -x "$AI/.venv/bin/python" ]]; then PY="$AI/.venv/bin/python"
else PY="python"; fi

cd "$AI"
echo "[eval] python harness ($PY)"
"$PY" -m evaluation.run "${ARGS[@]}"
STATUS=$?

if [[ $LARAVEL -eq 1 ]]; then
  echo "[eval] exporting STEP 35 payloads"
  "$PY" -m evaluation.export_laravel
  mkdir -p "$ROOT/backend/storage/app/ai-evaluation-benchmark"
  cp "$AI"/evaluation/results/laravel_import/*.json "$ROOT/backend/storage/app/ai-evaluation-benchmark/"
  echo "[eval] importing into Laravel (docker compose exec app)"
  (cd "$ROOT" && docker compose exec -T app php artisan ai-evaluation:import storage/app/ai-evaluation-benchmark --run --sync --replace)
fi
exit $STATUS
