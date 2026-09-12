#!/usr/bin/env bash
# STEP 43 §31 — concurrent-user capacity: run the representative workflow at fixed VU levels and print a table.
#   bash performance/capacity.sh [tag] [levels...]      default levels: 1 5 10 25 50 100, 45 s each
set -uo pipefail
cd "$(dirname "$0")/.."
TAG="${1:-capacity}"; shift || true
LEVELS=("${@:-1 5 10 25 50 100}"); LEVELS=($LEVELS)
DUR="${DURATION:-45s}"
for v in "${LEVELS[@]}"; do
  echo "▶ ${v} VUs for ${DUR}"
  PROFILE=fixed RUN_TAG="${TAG}-vu${v}" bash performance/run.sh full-workflow.js -e VUS="$v" -e DURATION="$DUR" -e THINK_TIME="${THINK_TIME:-5}" > "performance/results/_log_${TAG}_vu${v}.txt" 2>&1
  grep -E "^\s+facultylens-perf-(app|mysql)" "performance/results/_log_${TAG}_vu${v}.txt"
done
python - "$TAG" "${LEVELS[@]}" <<'EOF'
import json,sys
tag=sys.argv[1]; levels=sys.argv[2:]
print(f"\n{'VUs':>4s} {'reqs':>6s} {'req/s':>6s} {'fail%':>6s} {'5xx':>4s} {'429':>4s} | {'journey p50':>11s} {'journey p95':>11s} | {'overview p95':>12s} {'analysis p95':>12s} {'courses p95':>11s} {'login p95':>9s}")
for v in levels:
    try: d=json.load(open(f"performance/results/full-workflow-fixed-{tag}-vu{v}.json"))
    except Exception as e: print(v, e); continue
    e=d['endpoints']; g=lambda k,f='p95': (e.get(k) or {}).get(f)
    dur=45
    print(f"{v:>4s} {d['http_reqs']:>6d} {d['http_reqs']/dur:>6.1f} {100*(d['http_req_failed_rate'] or 0):>6.2f} {d['app_errors']:>4d} {d['rate_limited_429']:>4d} | {g('journey_total','med')!s:>11s} {g('journey_total')!s:>11s} | {g('analytics_overview')!s:>12s} {g('analysis_read')!s:>12s} {g('courses_list')!s:>11s} {g('login')!s:>9s}")
EOF
