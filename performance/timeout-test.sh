#!/usr/bin/env bash
# STEP 43 — timeout / dependency-failure behaviour test (real run, not simulated).
# Pauses the AI container so Laravel's HTTP client hits AI_SERVICE_CONNECT_TIMEOUT, dispatches an async analysis,
# observes the status endpoint, then unpauses the container and checks the queued job recovers via retry.
# Usage: bash performance/timeout-test.sh   (perf stack must be up; writes performance/results/timeout-behaviour.json)
set -euo pipefail
BASE="${BASE:-http://127.0.0.1:8090}"
EMAIL="${PERF_EMAIL:-perf.faculty@example.com}"; PASS="${PERF_PASSWORD:-PerfTest#2026}"
AI="${AI_CONTAINER:-facultylens-perf-ai-service}"
PAUSE_FOR="${PAUSE_FOR:-45}"
JAR=$(mktemp); trap 'rm -f "$JAR"; docker unpause "$AI" >/dev/null 2>&1 || true' EXIT
H=(-s -H "Accept: application/json" -H "Origin: $BASE" -H "Referer: $BASE/" -b "$JAR" -c "$JAR")

curl "${H[@]}" -o /dev/null "$BASE/sanctum/csrf-cookie"
xsrf() { python - "$JAR" <<'EOF'
import sys,urllib.parse
for l in open(sys.argv[1]):
    p=l.strip().split('\t')
    if len(p)>6 and p[5]=='XSRF-TOKEN': print(urllib.parse.unquote(p[6]))
EOF
}
curl "${H[@]}" -H "X-XSRF-TOKEN: $(xsrf)" -H "Content-Type: application/json" -o /dev/null \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}" "$BASE/api/auth/login"
AID=$(curl "${H[@]}" "$BASE/api/assessments?per_page=100" | python -c "import json,sys; d=json.load(sys.stdin)['data']; d=d if isinstance(d,list) else d['data']; print([a['id'] for a in d if '(10 questions)' in a['title']][0])")
echo "assessment $AID; pausing $AI for ${PAUSE_FOR}s"
docker pause "$AI" >/dev/null
T0=$(date +%s.%N)
ACC=$(curl "${H[@]}" -H "X-XSRF-TOKEN: $(xsrf)" -H "Content-Type: application/json" -w " HTTP%{http_code} %{time_total}s" -d '{}' "$BASE/api/ai/assessments/$AID/analyze?async=1")
echo "accept: ${ACC:0:200}"
TIMELINE=()
END=$(( $(date +%s) + PAUSE_FOR + 240 )); UNPAUSED=0; FINAL=""
while [ "$(date +%s)" -lt "$END" ]; do
  sleep 3
  NOW=$(python -c "import time;print(round(time.time()-$T0,1))")
  S=$(curl "${H[@]}" "$BASE/api/ai/assessments/$AID/analysis-status" | python -c "import json,sys; d=json.load(sys.stdin); print(d.get('analysis_status'), (d.get('error_message') or d.get('message') or '')[:80])")
  echo "t+${NOW}s status: $S"; TIMELINE+=("{\"t\":$NOW,\"status\":\"${S//\"/\'}\"}")
  if [ "$UNPAUSED" = 0 ] && python -c "import sys; sys.exit(0 if $NOW>=$PAUSE_FOR else 1)"; then docker unpause "$AI" >/dev/null; UNPAUSED=1; echo "t+${NOW}s AI container unpaused"; fi
  case "$S" in completed*) FINAL=completed; [ "$UNPAUSED" = 1 ] && break ;; failed*) FINAL=failed; [ "$UNPAUSED" = 1 ] && break ;; esac
done
python - "$FINAL" "${TIMELINE[@]}" <<'EOF' > performance/results/timeout-behaviour.json
import json,sys
print(json.dumps({"final_status": sys.argv[1], "timeline": [json.loads(x) for x in sys.argv[2:]]}, indent=1))
EOF
echo "final: $FINAL (performance/results/timeout-behaviour.json)"
