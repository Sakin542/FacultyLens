#!/usr/bin/env bash
# STEP 42 §12 — AI outage regression against the running Docker stack.
#
#   1. create course + assessment + 2 questions (via generation-free path: previous questions are not needed)
#   2. run a successful analysis (v1 completed)
#   3. docker compose stop ai-service  -> run analysis -> expect controlled 502/504, no crash,
#      v1 still completed, a separate failed attempt recorded, status endpoint = failed
#   4. docker compose start ai-service -> wait for /ready -> run analysis -> expect success, v2 current, v1 intact
#
# Usage: bash backend/tests/e2e_ai_outage.sh      (exit code non-zero on any failed expectation)
set -euo pipefail
BASE="${BASE:-http://127.0.0.1:8080}"
AI="${AI:-http://127.0.0.1:8001}"
JAR="$(mktemp)"; STAMP="$(date +%s)"
EMAIL="outage.$STAMP@university.edu"; PASS="Outage#2026"
FAILS=0

api() { local m="$1" p="$2" d="${3:-}"; local xsrf; xsrf=$(grep XSRF-TOKEN "$JAR" | awk '{print $7}' | python -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))")
  if [[ -n "$d" ]]; then curl -s -b "$JAR" -c "$JAR" -X "$m" "$BASE/api$p" -H "Accept: application/json" -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $xsrf" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" -d "$d"
  else curl -s -b "$JAR" -c "$JAR" -X "$m" "$BASE/api$p" -H "Accept: application/json" -H "X-XSRF-TOKEN: $xsrf" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/"; fi; }
apicode() { local m="$1" p="$2" d="${3:-}"; local xsrf; xsrf=$(grep XSRF-TOKEN "$JAR" | awk '{print $7}' | python -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))")
  curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -c "$JAR" -X "$m" "$BASE/api$p" -H "Accept: application/json" -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $xsrf" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" ${d:+-d "$d"}; }
py() { python -c "import sys,json;d=json.load(sys.stdin);print($1)"; }
sql() { docker compose exec -T mysql mysql -uroot -proot123 facultylens -N -s -e "$1" 2>/dev/null | tr -d '\r'; }
expect() { if [[ "$2" == "$3" ]]; then echo "  ✓ $1: $2"; else echo "  ✗ $1: expected [$3] got [$2]"; FAILS=$((FAILS+1)); fi; }
expect_in() { if [[ " $3 " == *" $2 "* ]]; then echo "  ✓ $1: $2"; else echo "  ✗ $1: expected one of [$3] got [$2]"; FAILS=$((FAILS+1)); fi; }
restart_ai() { docker compose start ai-service >/dev/null 2>&1 || true; }
trap restart_ai EXIT

echo "== 0. Stack readiness"
expect "AI service reachable" "$(curl -s "$AI/health" | py "d['status']")" "ok"

echo "== 1. Faculty + course + assessment + questions"
curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" >/dev/null
api POST /auth/register "{\"name\":\"Dr. Outage\",\"email\":\"$EMAIL\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"CSE\",\"designation\":\"Professor\"}" | py "d.get('message')"
api POST /auth/login "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}" | py "d.get('message')"
COURSE=$(api POST /courses "{\"course_code\":\"OUT-$STAMP\",\"course_name\":\"Outage Course\",\"semester\":\"Fall\",\"academic_year\":\"2026\",\"credits\":3,\"status\":\"active\"}" | py "d['data']['id']")
api POST "/courses/$COURSE/learning-outcomes" '{"code":"LO1","description":"Explain relational normalization and functional dependencies.","cognitive_level":"Understand","sort_order":1}' >/dev/null
ASSESS=$(api POST "/courses/$COURSE/assessments" '{"title":"Outage Midterm","type":"midterm","total_marks":20,"duration_minutes":60,"status":"draft"}' | py "d['data']['id']")
# Questions enter through upload/generation; seed two rows directly for this infrastructure test.
sql "INSERT INTO questions (assessment_id, question_number, question_text, question_type, marks, difficulty_level, cognitive_level, created_at, updated_at) VALUES ($ASSESS,1,'Explain the difference between 2NF and 3NF with an example.','descriptive',10,'medium','Understand',NOW(),NOW()),($ASSESS,2,'Given relation R(A,B,C) with FDs A->B, B->C, identify the candidate key and normalize to 3NF.','problem_solving',10,'hard','Apply',NOW(),NOW());"
expect "questions seeded" "$(sql "SELECT COUNT(*) FROM questions WHERE assessment_id=$ASSESS")" "2"

echo "== 2. Baseline analysis with AI up"
R1=$(api POST /ai/analyze-assessment "{\"assessment_id\":$ASSESS}")
expect "analysis 1 status" "$(echo "$R1" | py "d['status']")" "success"
expect "v1 completed" "$(sql "SELECT analysis_status FROM analysis_reports WHERE assessment_id=$ASSESS AND analysis_version=1")" "completed"
V1_SCORE=$(sql "SELECT overall_score FROM analysis_reports WHERE assessment_id=$ASSESS AND analysis_version=1")
echo "  v1 score: $V1_SCORE"

echo "== 3. Stop the AI service and run the analysis again"
docker compose stop ai-service >/dev/null
sleep 2
CODE=$(apicode POST /ai/analyze-assessment "{\"assessment_id\":$ASSESS}")
expect_in "controlled error while AI is down" "$CODE" "502 503 504"
BODY=$(api POST /ai/analyze-assessment "{\"assessment_id\":$ASSESS}")
expect "no stack trace leaked" "$(echo "$BODY" | python -c "import sys;b=sys.stdin.read();print('leak' if ('Exception' in b or '.php' in b or 'SQLSTATE' in b) else 'clean')")" "clean"
expect "v1 still completed" "$(sql "SELECT analysis_status FROM analysis_reports WHERE assessment_id=$ASSESS AND analysis_version=1")" "completed"
expect "v1 score unchanged" "$(sql "SELECT overall_score FROM analysis_reports WHERE assessment_id=$ASSESS AND analysis_version=1")" "$V1_SCORE"
expect "failed attempt recorded separately" "$(sql "SELECT COUNT(*) FROM analysis_reports WHERE assessment_id=$ASSESS AND analysis_status='failed'")" "1"
expect "exactly one current report" "$(sql "SELECT COUNT(*) FROM analysis_reports WHERE assessment_id=$ASSESS AND is_current=1")" "1"
expect "status endpoint reports failure" "$(api GET "/ai/assessments/$ASSESS/analysis-status" | py "d['analysis_status']")" "failed"
sleep 6  # readiness probes are cached for 5 s
expect "readiness degraded" "$(curl -s "$BASE/api/health/ready" | py "d['status'] + '/' + d['components']['ai_service']")" "degraded/error"
expect "app still alive" "$(curl -s "$BASE/api/health" | py "d['status']")" "ok"

echo "== 4. Restart the AI service and recover"
docker compose start ai-service >/dev/null
for i in $(seq 1 60); do if curl -sf "$AI/ready" >/dev/null 2>&1; then break; fi; sleep 2; done
expect "AI ready again" "$(curl -s "$AI/ready" | py "d.get('status')")" "ready"
R3=$(api POST /ai/analyze-assessment "{\"assessment_id\":$ASSESS}")
expect "analysis after recovery" "$(echo "$R3" | py "d['status']")" "success"
expect "v1 still completed after recovery" "$(sql "SELECT analysis_status FROM analysis_reports WHERE assessment_id=$ASSESS AND analysis_version=1")" "completed"
expect "recovered run is version 2" "$(sql "SELECT analysis_version FROM analysis_reports WHERE assessment_id=$ASSESS AND is_current=1")" "2"
expect "no failed rows left behind" "$(sql "SELECT COUNT(*) FROM analysis_reports WHERE assessment_id=$ASSESS AND analysis_status='failed'")" "0"
expect "history lists 2 versions" "$(api GET "/assessments/$ASSESS/analysis-history" | py "d['data']['total_versions']")" "2"
expect "status endpoint completed" "$(api GET "/ai/assessments/$ASSESS/analysis-status" | py "d['analysis_status']")" "completed"

echo
if [[ $FAILS -eq 0 ]]; then echo "AI OUTAGE REGRESSION: PASS"; else echo "AI OUTAGE REGRESSION: $FAILS FAILED"; exit 1; fi
