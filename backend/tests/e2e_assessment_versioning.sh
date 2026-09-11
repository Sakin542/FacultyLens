#!/usr/bin/env bash
# STEP 38 E2E: Login -> Course -> Assessment -> Versions -> Create v1 -> Add questions (v1 draft) -> Finalize v1
#              -> Create v2 -> Modify Q3 -> Compare v1 vs v2 -> Verify Q3 changed / v1 unchanged -> Finalize v2
#              -> Attempt to edit v2 (blocked) -> Student submission protection -> Restore v1 as v3 -> report/history/isolation
set -euo pipefail
BASE="${BASE:-http://127.0.0.1:8080}"
JAR="$(mktemp)"
EMAIL="versioning.e2e.$(date +%s)@university.edu"
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
py() { python -c "import sys,json;d=json.load(sys.stdin);print($1)"; }
code() { # path [method] [json]
  local xsrf; xsrf=$(grep XSRF-TOKEN "$JAR" | awk '{print $7}' | python -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))")
  if [[ -n "${3:-}" ]]; then
    curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -X "${2:-GET}" "$BASE/api$1" -H "Accept: application/json" -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $xsrf" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" -d "$3"
  else
    curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -X "${2:-GET}" "$BASE/api$1" -H "Accept: application/json" -H "X-XSRF-TOKEN: $xsrf" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/"
  fi
}
sql() { docker compose exec -T mysql mysql -uroot -proot123 facultylens -N -e "$1" 2>/dev/null | tr -d '\r'; }
expect() { # label actual expected
  if [[ "$2" == "$3" ]]; then echo "  ok   $1 = $2"; else echo "  FAIL $1: expected '$3' got '$2'"; exit 1; fi
}

echo "== CSRF + register + login"
curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" >/dev/null
api POST /auth/register "{\"name\":\"E2E Versioning\",\"email\":\"$EMAIL\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"CSE\",\"designation\":\"Lecturer\"}" | head -c 80; echo
api POST /auth/login "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}" | head -c 80; echo

echo "== Open Course -> Open Assessment (CSE101 Database Systems Midterm, 30 marks, 3 questions)"
COURSE=$(api POST /courses '{"course_code":"CSE101","course_name":"Database Systems","semester":"Fall","academic_year":"2026","credits":3,"status":"active"}' | py "d['data']['id']")
CO1=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"CO1","description":"Explain relational database concepts.","cognitive_level":"Understand","sort_order":1}' | py "d['data']['id']")
CO2=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"CO2","description":"Apply normalization to design schemas.","cognitive_level":"Apply","sort_order":2}' | py "d['data']['id']")
ASSESS=$(api POST "/courses/$COURSE/assessments" '{"title":"Database Systems Midterm","type":"midterm","total_marks":30,"duration_minutes":90,"status":"draft"}' | py "d['data']['id']")
docker compose exec -T app php artisan tinker --execute="
\$a=$ASSESS; foreach([['Explain SQL.','easy','Understand',$CO1],['Explain normalization.','medium','Apply',$CO2],['Design a schema for a library.','hard','Create',$CO2]] as \$i=>\$s){
\App\Models\Question::create(['assessment_id'=>\$a,'question_number'=>\$i+1,'question_text'=>\$s[0],'question_type'=>'descriptive','marks'=>10,'difficulty_level'=>\$s[1],'cognitive_level'=>\$s[2],'learning_outcome_id'=>\$s[3]]); } echo 'ok';" | tr -dc 'a-z'; echo
echo "course=$COURSE assessment=$ASSESS"

echo "== Open Versions (empty)"
EMPTY=$(api GET "/assessments/$ASSESS/versions")
expect "versions" "$(echo "$EMPTY" | py "len(d['data']['versions'])")" "0"
expect "permissions.edit" "$(echo "$EMPTY" | py "d['data']['permissions']['edit']")" "True"

echo "== Create v1 (snapshot of live assessment)"
V1=$(api POST "/assessments/$ASSESS/versions" '{}')
V1ID=$(echo "$V1" | py "d['data']['version']['id']")
expect "v1 label" "$(echo "$V1" | py "d['data']['version']['version_label']")" "v1.0"
expect "v1 status" "$(echo "$V1" | py "d['data']['version']['status']")" "DRAFT"
expect "v1 questions" "$(echo "$V1" | py "d['data']['version']['question_count']")" "3"
expect "v1 rows in DB" "$(sql "SELECT COUNT(*) FROM assessment_version_questions WHERE assessment_version_id=$V1ID")" "3"
expect "client version number ignored" "$(api POST "/assessments/$ASSESS/versions" '{"version_number":77,"change_summary":"attempt to force numbering"}' | py "d['data']['version']['version_number']")" "2"
# the throwaway v2 above is archived so the flow below is deterministic (its number is never reused)
TMPID=$(sql "SELECT id FROM assessment_versions WHERE assessment_id=$ASSESS AND version_number=2")
api POST "/assessment-versions/$TMPID/archive" >/dev/null

echo "== Add a question to draft v1 (Q4), then finalize v1"
ROWS=$(echo "$V1" | python -c "
import sys,json; d=json.load(sys.stdin); rows=[{k:q[k] for k in ['original_question_id','question_number','question_text','question_type','marks','difficulty_level','cognitive_level','learning_outcome_id']} for q in d['data']['version']['questions']]
rows.append({'question_number':4,'question_text':'Write an SQL query using JOIN.','question_type':'problem_solving','marks':10,'difficulty_level':'medium','cognitive_level':'Apply','learning_outcome_id':$CO1})
print(json.dumps({'questions':rows}))")
UPD=$(api PUT "/assessment-versions/$V1ID" "$ROWS")
expect "v1 question_count" "$(echo "$UPD" | py "d['data']['version']['question_count']")" "4"
expect "v1 total_marks recomputed" "$(echo "$UPD" | py "int(d['data']['version']['total_marks'])")" "40"
FIN1=$(api POST "/assessment-versions/$V1ID/finalize")
expect "v1 finalized" "$(echo "$FIN1" | py "d['data']['version']['status']")" "FINALIZED"
expect "live questions untouched by finalize" "$(sql "SELECT COUNT(*) FROM questions WHERE assessment_id=$ASSESS")" "3"

echo "== Create v2 from v1 -> Modify Q3"
V2=$(api POST "/assessments/$ASSESS/versions" "{\"based_on_version_id\":$V1ID,\"version_type\":\"MAJOR\",\"change_summary\":\"Updated Q3 to target 2NF/3NF with examples\"}")
V2ID=$(echo "$V2" | py "d['data']['version']['id']")
expect "v2 label" "$(echo "$V2" | py "d['data']['version']['version_label']")" "v3.0"
expect "v2 based_on" "$(echo "$V2" | py "d['data']['version']['based_on_version_id']")" "$V1ID"
expect "v2 cloned questions" "$(echo "$V2" | py "d['data']['version']['question_count']")" "4"
ROWS2=$(echo "$V2" | python -c "
import sys,json; d=json.load(sys.stdin); rows=[{k:q[k] for k in ['original_question_id','question_number','question_text','question_type','marks','difficulty_level','cognitive_level','learning_outcome_id']} for q in d['data']['version']['questions']]
rows[2]['question_text']='Explain 2NF and 3NF with suitable examples.'; rows[2]['marks']=15
print(json.dumps({'questions':rows}))")
UPD2=$(api PUT "/assessment-versions/$V2ID" "$ROWS2")
expect "v2 Q3 text" "$(echo "$UPD2" | py "d['data']['version']['questions'][2]['question_text']")" "Explain 2NF and 3NF with suitable examples."
expect "v2 total marks" "$(echo "$UPD2" | py "int(d['data']['version']['total_marks'])")" "45"

echo "== Compare v1 vs v2 -> Q3 changed, v1 unchanged"
CMP=$(api GET "/assessment-versions/$V1ID/compare/$V2ID")
echo "$CMP" | py "'summary: ' + str(d['data']['summary'])"
expect "modified" "$(echo "$CMP" | py "d['data']['summary']['modified']")" "1"
expect "unchanged" "$(echo "$CMP" | py "d['data']['summary']['unchanged']")" "3"
expect "detected change type" "$(echo "$CMP" | py "d['data']['summary']['detected_change_type']")" "MAJOR"
expect "marks difference" "$(echo "$CMP" | py "int(d['data']['marks']['total']['difference'])")" "5"
Q3=$(echo "$CMP" | py "json.dumps([i for i in d['data']['questions']['items'] if i['question_number']==3][0])")
expect "Q3 status" "$(echo "$Q3" | py "d['status']")" "MODIFIED"
expect "Q3 fields" "$(echo "$Q3" | py "sorted(c['field'] for c in d['changes'])")" "['marks', 'question_text']"
expect "Q3 v1 text" "$(echo "$Q3" | py "d['from']['question_text']")" "Design a schema for a library."
expect "v1 Q3 in DB unchanged" "$(sql "SELECT question_text FROM assessment_version_questions WHERE assessment_version_id=$V1ID AND question_number=3")" "Design a schema for a library."
expect "live Q3 unchanged" "$(sql "SELECT question_text FROM questions WHERE assessment_id=$ASSESS AND question_number=3")" "Design a schema for a library."

echo "== Finalize v2 -> v1 auto-archived -> attempt to edit v2 is blocked"
FIN2=$(api POST "/assessment-versions/$V2ID/finalize")
expect "v2 finalized" "$(echo "$FIN2" | py "d['data']['version']['status']")" "FINALIZED"
expect "v1 archived" "$(sql "SELECT status FROM assessment_versions WHERE id=$V1ID")" "ARCHIVED"
expect "edit finalized v2" "$(code "/assessment-versions/$V2ID" PUT '{"title":"Nope"}')" "409"
expect "edit archived v1" "$(code "/assessment-versions/$V1ID" PUT '{"title":"Nope"}')" "409"
expect "v2 title intact" "$(sql "SELECT title FROM assessment_versions WHERE id=$V2ID")" "Database Systems Midterm"
expect "current version" "$(api GET "/assessments/$ASSESS/versions" | py "d['data']['current_version_id']")" "$V2ID"

echo "== Student submission protection (submission references v2; historical wording survives later edits)"
STU=$(api POST /students '{"student_identifier":"STU-001","name":"Student One"}' | py "d['data']['id']")
SUB=$(api POST "/assessments/$ASSESS/submissions" "{\"student_id\":$STU}" | py "d['data']['id']")
expect "submission.assessment_version_id" "$(sql "SELECT assessment_version_id FROM student_submissions WHERE id=$SUB")" "$V2ID"
LIVEQ2=$(sql "SELECT id FROM questions WHERE assessment_id=$ASSESS AND question_number=2")
api POST "/submissions/$SUB/answers" "{\"question_id\":$LIVEQ2,\"answer_text\":\"Normalization removes redundancy.\"}" >/dev/null
SNAP=$(sql "SELECT assessment_version_question_id FROM student_answers WHERE student_submission_id=$SUB")
expect "answer -> v2 snapshot row" "$(sql "SELECT assessment_version_id FROM assessment_version_questions WHERE id=$SNAP")" "$V2ID"
docker compose exec -T app php artisan tinker --execute="\App\Models\Question::find($LIVEQ2)->update(['question_text'=>'Explain 2NF and 3NF.']); echo 'ok';" | tr -dc 'a-z'; echo
SHOWN=$(api GET "/submissions/$SUB")
expect "submission shows version" "$(echo "$SHOWN" | py "d['data']['assessment_version']['version_label']")" "v3.0"
expect "historical Q2 wording" "$(echo "$SHOWN" | py "[q for q in d['data']['questions'] if q['id']==$LIVEQ2][0]['question_text']")" "Explain normalization."
expect "live Q2 wording" "$(echo "$SHOWN" | py "[q for q in d['data']['questions'] if q['id']==$LIVEQ2][0]['current_question_text']")" "Explain 2NF and 3NF."

echo "== Restore v1 as new version -> v4 created, nothing overwritten"
RES=$(api POST "/assessment-versions/$V1ID/restore" '{}')
V3ID=$(echo "$RES" | py "d['data']['version']['id']")
expect "restored label" "$(echo "$RES" | py "d['data']['version']['version_label']")" "v4.0"
expect "restored status" "$(echo "$RES" | py "d['data']['version']['status']")" "DRAFT"
expect "restored based_on" "$(echo "$RES" | py "d['data']['version']['based_on_version_id']")" "$V1ID"
expect "restored summary" "$(echo "$RES" | py "d['data']['version']['change_summary']")" "Restored structure from v1.0"
expect "restored Q3 text" "$(echo "$RES" | py "d['data']['version']['questions'][2]['question_text']")" "Design a schema for a library."
expect "v2 still finalized" "$(sql "SELECT status FROM assessment_versions WHERE id=$V2ID")" "FINALIZED"
expect "total versions" "$(sql "SELECT COUNT(*) FROM assessment_versions WHERE assessment_id=$ASSESS")" "4"

echo "== Review workflow + validation gate on the restored draft"
api POST "/assessment-versions/$V3ID/submit-review" | py "('submit-review ->', d['data']['version']['status'], d['data']['version']['validation_status'])"
api POST "/assessment-versions/$V3ID/approve" | py "('approve ->', d['data']['version']['status'])"
expect "approved is immutable" "$(code "/assessment-versions/$V3ID" PUT '{"title":"Nope"}')" "409"
V5=$(api POST "/assessments/$ASSESS/versions" "{\"based_on_version_id\":$V3ID,\"version_type\":\"MINOR\",\"change_summary\":\"Wording tweak (intentionally broken marks)\"}")
V5ID=$(echo "$V5" | py "d['data']['version']['id']")
expect "minor label" "$(echo "$V5" | py "d['data']['version']['version_label']")" "v4.1"
api PUT "/assessment-versions/$V5ID" '{"total_marks":99}' >/dev/null
BAD=$(api POST "/assessment-versions/$V5ID/finalize")
echo "$BAD" | py "(d['message'], [e['code'] for e in d['data']['validation']['errors']])"
expect "finalize blocked" "$(code "/assessment-versions/$V5ID/finalize" POST)" "422"

echo "== Version analysis / blueprint endpoints + history + report + analytics identify versions"
api GET "/assessment-versions/$V2ID/analysis" | py "('analysis status', d['data']['status'])"
api GET "/assessment-versions/$V2ID/blueprint" | py "('blueprint snapshot', d['data']['blueprint'], 'profile difficulty', d['data']['profile']['difficulty'])"
docker compose exec -T app php artisan tinker --execute="\App\Models\AnalysisReport::create(['assessment_id'=>$ASSESS,'analysis_version'=>1,'is_current'=>true,'overall_score'=>81,'analysis_status'=>'completed','analyzed_at'=>now(),'total_questions'=>3]); echo 'ok';" | tr -dc 'a-z'; echo
HIST=$(api GET "/assessments/$ASSESS/analysis-history")
echo "$HIST" | py "[(h['version'], h['assessment_version']) for h in d['data']['history']]"
api GET "/analytics/overview?course_id=$COURSE&fresh=1" | py "[(r['title'], r['current_version'], r['version_count']) for r in d['data']['assessments']]"
REP=$(api GET "/assessments/$ASSESS/report")
echo "$REP" | py "('report version', d['data']['assessment']['version_label'], d['data']['assessment']['version_status'], d['data']['assessment_version'] and d['data']['assessment_version']['analysis_status'])" 2>/dev/null || echo "$REP" | head -c 200

echo "== Audit trail"
sql "SELECT action, COUNT(*) FROM audit_logs WHERE action LIKE 'ASSESSMENT_VERSION%' AND user_id=(SELECT id FROM users WHERE email='$EMAIL') GROUP BY action" | tr '\t' '='
expect "no question text in audit metadata" "$(sql "SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'ASSESSMENT_VERSION%' AND metadata LIKE '%Explain SQL%'")" "0"

echo "== Cross-faculty isolation"
JAR2="$(mktemp)"; JAR_SAVE="$JAR"; JAR="$JAR2"
curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" >/dev/null
api POST /auth/register "{\"name\":\"Other\",\"email\":\"other.$EMAIL\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"EEE\",\"designation\":\"Lecturer\"}" >/dev/null
api POST /auth/login "{\"email\":\"other.$EMAIL\",\"password\":\"$PASS\"}" >/dev/null
expect "other list versions" "$(code "/assessments/$ASSESS/versions")" "403"
expect "other create version" "$(code "/assessments/$ASSESS/versions" POST '{"change_summary":"hijack"}')" "403"
expect "other view version" "$(code "/assessments/$ASSESS/versions/$V2ID")" "403"
expect "other finalize" "$(code "/assessment-versions/$V3ID/finalize" POST)" "403"
expect "other restore" "$(code "/assessment-versions/$V1ID/restore" POST)" "403"
expect "other compare" "$(code "/assessment-versions/$V1ID/compare/$V2ID")" "403"
JAR="$JAR_SAVE"
expect "anonymous" "$(curl -s -o /dev/null -w "%{http_code}" "$BASE/api/assessments/$ASSESS/versions" -H "Accept: application/json")" "401"
echo "== E2E complete"
