#!/usr/bin/env bash
# STEP 25 E2E: Login -> Course -> LO -> Assessment -> Question -> Generate -> Edit -> Save -> Approve -> Regenerate
set -euo pipefail
BASE="${BASE:-http://127.0.0.1:8080}"
JAR="$(mktemp)"
EMAIL="rubric.e2e.$(date +%s)@university.edu"
PASS="Password123!"

api() { # method path [json]
  local m="$1" p="$2" d="${3:-}"
  local xsrf; xsrf=$(grep XSRF-TOKEN "$JAR" | awk '{print $7}' | python -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))")
  if [[ -n "$d" ]]; then
    curl -s -b "$JAR" -c "$JAR" -X "$m" "$BASE/api$p" -H "Accept: application/json" -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $xsrf" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" -d "$d"
  else
    curl -s -b "$JAR" -c "$JAR" -X "$m" "$BASE/api$p" -H "Accept: application/json" -H "X-XSRF-TOKEN: $xsrf" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/"
  fi
}
jget() { python -c "import sys,json;d=json.load(sys.stdin);print(eval(\"d$1\"))"; }

echo "== CSRF + register + login"
curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" >/dev/null
api POST /auth/register "{\"name\":\"E2E Faculty\",\"email\":\"$EMAIL\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"CSE\",\"designation\":\"Lecturer\"}" | head -c 200; echo
api POST /auth/login "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}" | head -c 150; echo

echo "== Course CSE101"
COURSE=$(api POST /courses '{"course_code":"CSE101","course_name":"Database Systems","semester":"Fall","academic_year":"2026","credits":3,"status":"active"}' | jget "['data']['id']")
echo "course=$COURSE"
LO=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"CO2","description":"Explain fundamental database concepts.","cognitive_level":"Understand","sort_order":1}' | jget "['data']['id']")
echo "lo=$LO"
ASSESS=$(api POST "/courses/$COURSE/assessments" '{"title":"Midterm Examination","type":"midterm","total_marks":10,"status":"draft"}' | jget "['data']['id']")
echo "assessment=$ASSESS"

echo "== Question (inserted via artisan tinker; no question CRUD endpoint exists)"
QID=$(docker compose exec -T app php artisan tinker --execute="echo \App\Models\Question::create(['assessment_id'=>$ASSESS,'question_number'=>1,'question_text'=>'Explain database normalization with suitable examples.','question_type'=>'descriptive','marks'=>10,'difficulty_level'=>'medium','cognitive_level'=>'Understand','learning_outcome_id'=>$LO])->id;" | tr -dc '0-9')
echo "question=$QID"

echo "== Generate rubric (Laravel -> FastAPI -> validate -> MySQL)"
GEN=$(api POST "/questions/$QID/rubrics/generate")
echo "$GEN" | head -c 600; echo
RID=$(echo "$GEN" | jget "['data']['id']")
echo "$GEN" | jget "['data']['status'], d['data']['version'], d['data']['criteria_total'], d['data']['ai_model']"

echo "== Edit + save draft (5 criteria -> 3, total must stay 10)"
api PUT "/rubrics/$RID" '{"title":"Faculty-adjusted normalization rubric","criteria":[{"criterion":"Definition of normalization","description":"Defines normalization and its purpose","max_marks":4,"expected_indicators":["reduces redundancy"]},{"criterion":"Normal forms","description":"Explains 1NF, 2NF, 3NF","max_marks":4},{"criterion":"Examples","description":"Relevant examples","max_marks":2}]}' | jget "['data']['title'], d['data']['status'], d['data']['criteria_total'], len(d['data']['criteria'])"

echo "== Invalid edit is rejected (total 12)"
api PUT "/rubrics/$RID" '{"criteria":[{"criterion":"A","description":"a","max_marks":6},{"criterion":"B","description":"b","max_marks":6}]}' | head -c 200; echo

echo "== Approve"
api POST "/rubrics/$RID/approve" | jget "['data']['status'], d['data']['approved_at']"

echo "== Refresh: rubric persisted in MySQL"
docker compose exec -T mysql mysql -uroot -proot123 facultylens -e "SELECT id,version,status,title,total_marks FROM rubrics WHERE question_id=$QID; SELECT rubric_id,sort_order,criterion,max_marks FROM rubric_criteria WHERE rubric_id=$RID;" 2>/dev/null
api GET "/questions/$QID/rubrics" | jget "['data'][0]['status'], d['data'][0]['title'], len(d['data'])"

echo "== Regenerate: v2 draft created, v1 stays APPROVED"
api POST "/rubrics/$RID/regenerate" | jget "['data']['version'], d['data']['status']"
api GET "/questions/$QID/rubrics" | python -c "import sys,json;d=json.load(sys.stdin);print([(r['version'], r['status']) for r in d['data']])"

echo "== Cross-faculty access blocked"
JAR2="$(mktemp)"; JAR_SAVE="$JAR"; JAR="$JAR2"
curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" >/dev/null
api POST /auth/register "{\"name\":\"Other\",\"email\":\"other.$EMAIL\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"EEE\",\"designation\":\"Lecturer\"}" >/dev/null
api POST /auth/login "{\"email\":\"other.$EMAIL\",\"password\":\"$PASS\"}" >/dev/null
echo -n "other GET rubric -> "; curl -s -o /dev/null -w "%{http_code}\n" -b "$JAR" "$BASE/api/rubrics/$RID" -H "Accept: application/json" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/"
echo -n "other generate  -> "; curl -s -o /dev/null -w "%{http_code}\n" -b "$JAR" -X POST "$BASE/api/questions/$QID/rubrics/generate" -H "Accept: application/json" -H "X-XSRF-TOKEN: $(grep XSRF-TOKEN "$JAR" | awk '{print $7}' | python -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))")" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/"
echo -n "anonymous GET   -> "; curl -s -o /dev/null -w "%{http_code}\n" "$BASE/api/rubrics/$RID" -H "Accept: application/json"
JAR="$JAR_SAVE"
echo "== E2E complete"
