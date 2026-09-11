#!/usr/bin/env bash
# STEP 40 performance smoke test (NOT a load test): measures p50/p95 latency of representative
# authenticated endpoints against the running stack, sequentially, with the account's real data.
#
#   bash scripts/perf-smoke.sh [email] [password] [iterations]
#
# Results are printed as a markdown table for docs/PRODUCTION_READINESS.md.
set -euo pipefail
BASE="${BASE:-http://127.0.0.1:8080}"
EMAIL="${1:-}"; PASS="${2:-Password123!}"; N="${3:-10}"
JAR="$(mktemp)"
hdr=(-H "Accept: application/json" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/")

curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" "${hdr[@]}" >/dev/null
xsrf() { grep XSRF-TOKEN "$JAR" | awk '{print $7}' | python -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))"; }
if [[ -z "$EMAIL" ]]; then
  EMAIL="perf.$(date +%s)@university.edu"
  curl -s -b "$JAR" -c "$JAR" -X POST "$BASE/api/auth/register" "${hdr[@]}" -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $(xsrf)" \
    -d "{\"name\":\"Perf Smoke\",\"email\":\"$EMAIL\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"CSE\",\"designation\":\"Lecturer\"}" >/dev/null
fi
curl -s -b "$JAR" -c "$JAR" -X POST "$BASE/api/auth/login" "${hdr[@]}" -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $(xsrf)" -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}" | head -c 60; echo

COURSE=$(curl -s -b "$JAR" "$BASE/api/courses" "${hdr[@]}" | python -c "import sys,json;d=json.load(sys.stdin);c=d.get('data',[]);c=c.get('data',c) if isinstance(c,dict) else c;print(c[0]['id'] if c else '')")
ASSESS=$(curl -s -b "$JAR" "$BASE/api/assessments" "${hdr[@]}" | python -c "import sys,json;d=json.load(sys.stdin);a=d.get('data',[]);a=a.get('data',a) if isinstance(a,dict) else a;print(a[0]['id'] if a else '')")

measure() { # label path [method json]
  local label="$1" path="$2" method="${3:-GET}" body="${4:-}" times=()
  for _ in $(seq 1 "$N"); do
    if [[ "$method" == "GET" ]]; then
      t=$(curl -s -o /dev/null -w "%{time_total} %{http_code}" -b "$JAR" "$BASE/api$path" "${hdr[@]}")
    else
      t=$(curl -s -o /dev/null -w "%{time_total} %{http_code}" -b "$JAR" -c "$JAR" -X "$method" "$BASE/api$path" "${hdr[@]}" -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $(xsrf)" -d "$body")
    fi
    times+=("$t")
  done
  printf '%s\n' "${times[@]}" | MSYS_NO_PATHCONV=1 python -c "
import sys,statistics
rows=[l.split() for l in sys.stdin.read().split('\n') if l.strip()]
ms=sorted(float(r[0])*1000 for r in rows); codes={r[1] for r in rows}
p50=ms[len(ms)//2]; p95=ms[min(len(ms)-1,int(round(len(ms)*0.95))-1)]
print(f'| {sys.argv[1]} | {sys.argv[2]} | {len(ms)} | {p50:.0f} ms | {p95:.0f} ms | {max(ms):.0f} ms | {\",\".join(sorted(codes))} |')" "$label" "$path"
}

echo
echo "| Endpoint | Path | n | p50 | p95 | max | HTTP |"
echo "|---|---|---|---|---|---|---|"
measure "Health (liveness)" "/health"
measure "Readiness" "/health/ready"
measure "Current user" "/auth/user"
measure "Courses list" "/courses"
measure "Assessments list" "/assessments"
[[ -n "$COURSE" ]] && measure "Course detail" "/courses/$COURSE"
[[ -n "$ASSESS" ]] && measure "Assessment detail" "/assessments/$ASSESS"
[[ -n "$ASSESS" ]] && measure "Assessment versions" "/assessments/$ASSESS/versions"
measure "Analytics overview (cached)" "/analytics/overview"
measure "Analytics overview (fresh)" "/analytics/overview?fresh=1"
measure "Report types" "/reports/types"
measure "Report filters" "/reports/filters"
[[ -n "$COURSE" ]] && measure "Report preview (quality/course)" "/reports/preview" POST "{\"report_type\":\"ASSESSMENT_QUALITY\",\"scope_type\":\"COURSE\",\"filters\":{\"course_id\":$COURSE}}"
measure "My reports" "/reports"
echo
echo "Account: $EMAIL · course=$COURSE assessment=$ASSESS · base=$BASE · sequential, single client (smoke only)"
