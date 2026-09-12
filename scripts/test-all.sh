#!/usr/bin/env bash
# STEP 41 — run every automated test suite of FacultyLens and exit non-zero if any fails.
#
#   backend   : php artisan test            (Laravel feature/unit — sqlite in-memory; live-AI tests skip if :8001 is down)
#   frontend  : tsc + vitest                (unit / component tests)
#   ai-service: ruff + pytest               (FastAPI)
#   e2e       : Playwright journeys 1-8     (needs `docker compose up -d`; opt-in with --e2e or E2E=1)
#   golden    : bash Golden Path + integrity (needs Docker stack; opt-in with --golden)
#
# Usage: bash scripts/test-all.sh [--e2e] [--golden] [--skip-ai] [--skip-frontend] [--skip-backend]
set -uo pipefail
cd "$(dirname "$0")/.."
RUN_E2E="${E2E:-0}"; RUN_GOLDEN=0; SKIP_AI=0; SKIP_FE=0; SKIP_BE=0
for a in "$@"; do case "$a" in
  --e2e) RUN_E2E=1;; --golden) RUN_GOLDEN=1;; --skip-ai) SKIP_AI=1;; --skip-frontend) SKIP_FE=1;; --skip-backend) SKIP_BE=1;;
  *) echo "unknown option $a"; exit 2;; esac; done

declare -a NAMES=() RESULTS=() TIMES=()
run() { # name command...
  local name="$1"; shift
  echo; echo "──────────────────────────────────────────────────────────────"; echo "▶ $name"; echo "  $*"
  local t0=$(date +%s)
  if "$@"; then RESULTS+=("PASS"); else RESULTS+=("FAIL"); fi
  NAMES+=("$name"); TIMES+=("$(( $(date +%s) - t0 ))s")
}
pyexe() { if [[ -x ai-service/.venv/Scripts/python.exe ]]; then echo ai-service/.venv/Scripts/python.exe; elif [[ -x ai-service/.venv/bin/python ]]; then echo ai-service/.venv/bin/python; else echo python; fi; }

if [[ $SKIP_BE -eq 0 ]]; then
  run "backend: php artisan test" bash -o pipefail -c 'cd backend && php artisan test 2>&1 | tail -12'
fi

if [[ $SKIP_FE -eq 0 ]]; then
  run "frontend: tsc --noEmit" bash -c 'cd frontend && npx tsc --noEmit -p tsconfig.json'
  run "frontend: vitest" bash -o pipefail -c 'cd frontend && npx vitest run 2>&1 | tail -8'
  run "frontend: build" bash -o pipefail -c 'cd frontend && npx vite build 2>&1 | tail -2'
fi

if [[ $SKIP_AI -eq 0 ]]; then
  PY=$(pyexe)
  run "ai-service: ruff" bash -c "cd ai-service && $PY -m ruff check app tests"
  run "ai-service: pytest" bash -o pipefail -c "cd ai-service && $PY -m pytest -q 2>&1 | tail -3"
fi

if [[ "$RUN_E2E" == "1" ]]; then
  if curl -sf http://127.0.0.1:8080/api/health >/dev/null 2>&1; then
    run "e2e: Playwright journeys (Chromium)" bash -o pipefail -c 'cd frontend && npx tsc -p tsconfig.e2e.json && npx playwright test 2>&1 | tail -25'
  else
    echo; echo "▶ e2e: Playwright — SKIPPED (backend not reachable on :8080; run: docker compose up -d)"; NAMES+=("e2e: Playwright"); RESULTS+=("SKIPPED"); TIMES+=("-")
  fi
fi

if [[ $RUN_GOLDEN -eq 1 ]]; then
  if curl -sf http://127.0.0.1:8080/api/health >/dev/null 2>&1; then
    run "golden path (Docker, live AI)" bash backend/tests/e2e_golden_path.sh
  else
    echo; echo "▶ golden path — SKIPPED (backend not reachable on :8080)"; NAMES+=("golden path"); RESULTS+=("SKIPPED"); TIMES+=("-")
  fi
fi

echo; echo "══════════════════════════════════════════════════════════════"; echo " FacultyLens test summary"; echo "══════════════════════════════════════════════════════════════"
FAILED=0
for i in "${!NAMES[@]}"; do
  printf ' %-42s %-8s %s\n' "${NAMES[$i]}" "${RESULTS[$i]}" "${TIMES[$i]}"
  [[ "${RESULTS[$i]}" == "FAIL" ]] && FAILED=$((FAILED+1))
done
echo "──────────────────────────────────────────────────────────────"
if [[ $FAILED -eq 0 ]]; then echo " ALL SUITES PASSED"; exit 0; else echo " $FAILED SUITE(S) FAILED"; exit 1; fi
