#!/usr/bin/env bash
# STEP 27 E2E: Login -> CSE101 -> Midterm -> Q "Explain database normalization." (10) -> approved rubric
#   -> submission + answer -> AI grading (queued, polled) -> suggested marks + criterion breakdown
#   -> faculty accept / edit -> final marks stored separately -> regenerate -> stale detection -> cross-faculty
# Requires: docker compose stack up (app :8080, ai-service, mysql, queue-worker).
set -euo pipefail
BASE="${BASE:-http://127.0.0.1:8080}"
JAR="$(mktemp)"
STAMP=$(date +%s)
EMAIL="grade.e2e.$STAMP@university.edu"
PASS="Password123!"
ORIGIN=(-H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/")

xsrf() { grep XSRF-TOKEN "$JAR" | awk '{print $7}' | python -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))"; }
api() { # method path [json]
  local m="$1" p="$2" d="${3:-}"
  if [[ -n "$d" ]]; then
    curl -s -b "$JAR" -c "$JAR" -X "$m" "$BASE/api$p" -H "Accept: application/json" -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $(xsrf)" "${ORIGIN[@]}" -d "$d"
  else
    curl -s -b "$JAR" -c "$JAR" -X "$m" "$BASE/api$p" -H "Accept: application/json" -H "X-XSRF-TOKEN: $(xsrf)" "${ORIGIN[@]}"
  fi
}
code() { curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -X "$1" "$BASE/api$2" -H "Accept: application/json" -H "X-XSRF-TOKEN: $(xsrf)" "${ORIGIN[@]}" ${3:+-H "Content-Type: application/json" -d "$3"}; }
py() { python -c "import sys,json;d=json.load(sys.stdin);print($1)"; }
wait_grading() { # answer_id -> waits for a terminal status, prints it
  local a="$1" s=""
  for _ in $(seq 1 40); do
    s=$(api GET "/student-answers/$a/ai-grading" | py "d['data']['grading_status']")
    [[ "$s" != "PENDING" && "$s" != "PROCESSING" ]] && break
    sleep 1.5
  done
  echo "$s"
}

echo "== Register + login"
curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" "${ORIGIN[@]}" >/dev/null
api POST /auth/register "{\"name\":\"E2E Faculty\",\"email\":\"$EMAIL\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"CSE\",\"designation\":\"Lecturer\"}" | head -c 80; echo
api POST /auth/login "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}" | head -c 80; echo

echo "== Course / LO / Assessment / Question (10 marks)"
COURSE=$(api POST /courses '{"course_code":"CSE101","course_name":"Database Systems","semester":"Fall","academic_year":"2026","credits":3,"status":"active"}' | py "d['data']['id']")
LO=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"CO2","description":"Explain fundamental database concepts.","cognitive_level":"Understand","sort_order":1}' | py "d['data']['id']")
ASSESS=$(api POST "/courses/$COURSE/assessments" '{"title":"Midterm Examination","type":"midterm","total_marks":10,"status":"draft"}' | py "d['data']['id']")
Q=$(docker compose exec -T app php artisan tinker --execute="
echo \App\Models\Question::create(['assessment_id'=>$ASSESS,'question_number'=>1,'question_text'=>'Explain database normalization and describe 1NF, 2NF and 3NF with an example.','question_type'=>'descriptive','marks'=>10,'difficulty_level'=>'medium','cognitive_level'=>'Understand','learning_outcome_id'=>$LO])->id;" | tr -dc '0-9')
echo "course=$COURSE assessment=$ASSESS question=$Q"

echo "== Student + submission + answer"
S1=$(api POST /students '{"student_identifier":"STU001","name":"Student One","section":"A"}' | py "d['data']['id']")
SUB=$(api POST "/assessments/$ASSESS/submissions" "{\"student_id\":$S1,\"submission_identifier\":\"MID-001\"}" | py "d['data']['id']")
ANS=$(api POST "/submissions/$SUB/answers" "{\"question_id\":$Q,\"answer_text\":\"Normalization is a process used to organize data and reduce redundancy. First normal form (1NF) requires atomic values with no repeating groups. Second normal form (2NF) removes partial dependency on the primary key. For example a student table with course name can be split into Student and Course tables.\"}" | py "d['data']['id']")
echo "submission=$SUB answer=$ANS"

echo "== No approved rubric yet -> AI grading must be refused"
echo -n "ai-grade without rubric -> "; code POST "/student-answers/$ANS/ai-grade"; echo
api POST "/student-answers/$ANS/ai-grade" | py "d['message']"

echo "== Generate + approve rubric (STEP 25)"
RID=$(api POST "/questions/$Q/rubrics/generate" | py "d['data']['id']")
api POST "/rubrics/$RID/approve" | py "(d['data']['status'], d['data']['version'], d['data']['total_marks'], [ (c['criterion'], c['max_marks']) for c in d['data']['criteria']])"

echo "== Request AI grading assistance (202, queued)"
api POST "/student-answers/$ANS/ai-grade" | py "(d['message'][:40], d['data']['grading_status'], d['data']['rubric_version'])"
echo -n "duplicate while active -> "; code POST "/student-answers/$ANS/ai-grade"; echo
echo -n "waiting for worker -> "; wait_grading "$ANS"
RES=$(api GET "/student-answers/$ANS/ai-grading")
echo "$RES" | py "('suggested', d['data']['suggested_marks'], '/', d['data']['maximum_marks'], 'stale', d['data']['is_stale'], 'model', d['data']['model_name'], d['data']['generation_method'])"
echo "$RES" | py "[(c['criterion'], c['suggested_marks'], '/', c['maximum_marks'], c['coverage_level']) for c in d['data']['criterion_results']]"
echo "$RES" | py "('criterion sum ok', abs(sum(c['suggested_marks'] for c in d['data']['criterion_results']) - d['data']['suggested_marks']) < 0.005)"
echo "$RES" | py "('strengths', d['data']['strengths'][:2], 'missing', d['data']['missing_elements'][:2])"
echo "$RES" | py "('feedback', d['data']['overall_feedback'][:90])"
RESULT_ID=$(echo "$RES" | py "d['data']['id']")
SUGGESTED=$(echo "$RES" | py "d['data']['suggested_marks']")
echo -n "completed result re-request -> "; code POST "/student-answers/$ANS/ai-grade"; echo

echo "== Faculty marks are still untouched; submission is AI_ASSISTED"
api GET "/submissions/$SUB" | py "(d['data']['grading_status'], d['data']['awarded_marks'], d['data']['questions'][0]['answer']['awarded_marks'], d['data']['questions'][0]['answer']['ai_grading']['suggested_marks'])"

echo "== Faculty final marks: invalid, then edit to a different value"
echo -n "final 12/10 -> "; code POST "/student-answers/$ANS/finalize-grade" '{"final_marks":12}'; echo
echo -n "final -1    -> "; code POST "/student-answers/$ANS/finalize-grade" '{"final_marks":-1}'; echo
FINAL=$(python -c "s=$SUGGESTED; print(min(10, s+0.5) if s<10 else s-0.5)")
api POST "/student-answers/$ANS/finalize-grade" "{\"final_marks\":$FINAL,\"faculty_feedback\":\"Good understanding, but provide more detail on 2NF and 3NF.\"}" \
  | py "('faculty final', d['data']['awarded_marks'], d['data']['answer_status'], 'ai suggested', d['data']['ai_grading']['suggested_marks'], d['data']['ai_grading']['faculty_decision'], d['data']['ai_grading']['grading_status'])"
api GET "/submissions/$SUB" | py "(d['data']['grading_status'], d['data']['awarded_marks'])"
docker compose exec -T mysql mysql -uroot -proot123 facultylens -e "SELECT sa.awarded_marks AS faculty_final, r.suggested_marks AS ai_suggested, r.maximum_marks, r.grading_status, r.faculty_decision, r.rubric_version, r.is_current FROM ai_grading_results r JOIN student_answers sa ON sa.id=r.student_answer_id WHERE r.student_answer_id=$ANS;" 2>/dev/null

echo "== Regenerate (old run preserved) and history"
api POST "/ai-grading/$RESULT_ID/regenerate" | py "(d['data']['grading_status'], d['data']['id'] != $RESULT_ID)"
echo -n "waiting for worker -> "; wait_grading "$ANS"
api GET "/student-answers/$ANS/ai-grading/history" | py "[(r['id'], r['grading_status'], r['is_current'], r['suggested_marks']) for r in d['data']]"

echo "== Stale detection: edit answer after grading"
api PUT "/student-answers/$ANS" '{"answer_text":"Normalization reduces redundancy."}' | py "d['data']['is_faculty_edited']"
api GET "/student-answers/$ANS/ai-grading" | py "(d['data']['is_stale'], d['data']['stale_reasons'])"

echo -n "audit entries -> "; docker compose exec -T mysql mysql -uroot -proot123 facultylens -N -e "SELECT GROUP_CONCAT(DISTINCT action ORDER BY action) FROM audit_logs WHERE action LIKE 'AI_GRADING%' OR action LIKE 'AI_SUGGESTION%' OR action='GRADE_FINALIZED';" 2>/dev/null
echo -n "answer text in audit metadata -> "; docker compose exec -T mysql mysql -uroot -proot123 facultylens -N -e "SELECT COUNT(*) FROM audit_logs WHERE metadata LIKE '%reduce redundancy%';" 2>/dev/null

echo "== Cross-faculty access"
JAR2="$(mktemp)"; JAR_SAVE="$JAR"; JAR="$JAR2"
curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" "${ORIGIN[@]}" >/dev/null
api POST /auth/register "{\"name\":\"Other\",\"email\":\"other.$EMAIL\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"EEE\",\"designation\":\"Lecturer\"}" >/dev/null
api POST /auth/login "{\"email\":\"other.$EMAIL\",\"password\":\"$PASS\"}" >/dev/null
echo -n "other request ai-grade  -> "; code POST "/student-answers/$ANS/ai-grade"; echo
echo -n "other view ai-grading   -> "; code GET "/student-answers/$ANS/ai-grading"; echo
echo -n "other regenerate        -> "; code POST "/ai-grading/$RESULT_ID/regenerate"; echo
echo -n "other finalize grade    -> "; code POST "/student-answers/$ANS/finalize-grade" '{"final_marks":1}'; echo
echo -n "anonymous ai-grading    -> "; curl -s -o /dev/null -w "%{http_code}\n" "$BASE/api/student-answers/$ANS/ai-grading" -H "Accept: application/json"
JAR="$JAR_SAVE"
echo "== E2E complete"
