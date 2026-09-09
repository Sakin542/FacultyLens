#!/usr/bin/env bash
# STEP 28 E2E: Login -> CSE101 -> Midterm -> "Explain database normalization." (10) -> approved rubric
#   -> submission + answer -> Analyze Rubric Alignment (queued, polled) -> criterion breakdown + evidence
#   -> AI grading (STEP 27) alongside -> faculty review -> regenerate -> stale -> cross-faculty
# Verifies: alignment != grade; AI suggested marks and faculty final marks stay separate.
set -euo pipefail
BASE="${BASE:-http://127.0.0.1:8080}"
JAR="$(mktemp)"
STAMP=$(date +%s)
EMAIL="align.e2e.$STAMP@university.edu"
PASS="Password123!"
ORIGIN=(-H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/")

xsrf() { grep XSRF-TOKEN "$JAR" | awk '{print $7}' | python -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))"; }
api() {
  local m="$1" p="$2" d="${3:-}"
  if [[ -n "$d" ]]; then
    curl -s -b "$JAR" -c "$JAR" -X "$m" "$BASE/api$p" -H "Accept: application/json" -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $(xsrf)" "${ORIGIN[@]}" -d "$d"
  else
    curl -s -b "$JAR" -c "$JAR" -X "$m" "$BASE/api$p" -H "Accept: application/json" -H "X-XSRF-TOKEN: $(xsrf)" "${ORIGIN[@]}"
  fi
}
code() { curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -X "$1" "$BASE/api$2" -H "Accept: application/json" -H "X-XSRF-TOKEN: $(xsrf)" "${ORIGIN[@]}" ${3:+-H "Content-Type: application/json" -d "$3"}; }
py() { python -c "import sys,json;d=json.load(sys.stdin);print($1)"; }
wait_status() { # path key -> waits for a terminal status
  local p="$1" k="$2" s=""
  for _ in $(seq 1 40); do
    s=$(api GET "$p" | py "d['data']['$k']")
    [[ "$s" != "PENDING" && "$s" != "PROCESSING" ]] && break
    sleep 1.5
  done
  echo "$s"
}

echo "== Register + login"
curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" "${ORIGIN[@]}" >/dev/null
api POST /auth/register "{\"name\":\"E2E Faculty\",\"email\":\"$EMAIL\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"CSE\",\"designation\":\"Lecturer\"}" | head -c 60; echo
api POST /auth/login "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}" | head -c 60; echo

echo "== Course / Assessment / Question"
COURSE=$(api POST /courses '{"course_code":"CSE101","course_name":"Database Systems","semester":"Fall","academic_year":"2026","credits":3,"status":"active"}' | py "d['data']['id']")
ASSESS=$(api POST "/courses/$COURSE/assessments" '{"title":"Midterm Examination","type":"midterm","total_marks":10,"status":"draft"}' | py "d['data']['id']")
Q=$(docker compose exec -T app php artisan tinker --execute="
echo \App\Models\Question::create(['assessment_id'=>$ASSESS,'question_number'=>1,'question_text'=>'Explain database normalization and describe 1NF, 2NF and 3NF with an example.','question_type'=>'descriptive','marks'=>10,'difficulty_level'=>'medium','cognitive_level'=>'Understand'])->id;" | tr -dc '0-9')
echo "course=$COURSE assessment=$ASSESS question=$Q"

echo "== Student + submission + answer"
S1=$(api POST /students '{"student_identifier":"STU001","name":"Student One","section":"A"}' | py "d['data']['id']")
SUB=$(api POST "/assessments/$ASSESS/submissions" "{\"student_id\":$S1,\"submission_identifier\":\"MID-001\"}" | py "d['data']['id']")
ANS=$(api POST "/submissions/$SUB/answers" "{\"question_id\":$Q,\"answer_text\":\"Normalization organizes data to reduce redundancy. First normal form requires atomic values.\"}" | py "d['data']['id']")
echo "submission=$SUB answer=$ANS"

echo "== No approved rubric -> alignment refused"
echo -n "rubric-alignment without rubric -> "; code POST "/student-answers/$ANS/rubric-alignment"; echo

echo "== Generate + approve rubric (STEP 25)"
RID=$(api POST "/questions/$Q/rubrics/generate" | py "d['data']['id']")
api POST "/rubrics/$RID/approve" | py "(d['data']['status'], d['data']['version'], [ (c['criterion'], c['max_marks']) for c in d['data']['criteria']])"

echo "== Analyze rubric alignment (202, queued)"
api POST "/student-answers/$ANS/rubric-alignment" | py "(d['status'], d['data']['analysis_status'], d['data']['rubric_version'])"
echo -n "duplicate while active -> "; code POST "/student-answers/$ANS/rubric-alignment"; echo
echo -n "waiting for worker -> "; wait_status "/student-answers/$ANS/rubric-alignment" analysis_status
AL=$(api GET "/student-answers/$ANS/rubric-alignment")
echo "$AL" | py "('overall', d['data']['overall_alignment_score'], '%', d['data']['alignment_status'], 'unweighted', d['data']['unweighted_alignment_score'], 'counts', d['data']['counts'])"
echo "$AL" | py "[(c['criterion'][:32], c['alignment_status'], c['alignment_score'], c['max_marks']) for c in d['data']['criterion_alignments']]"
echo "$AL" | py "('weighted ok', abs(sum(c['alignment_score']*c['max_marks'] for c in d['data']['criterion_alignments'])/sum(c['max_marks'] for c in d['data']['criterion_alignments'])*100 - d['data']['overall_alignment_score']) < 0.05)"
echo "$AL" | py "('evidence grounded', all(all(e.rstrip('…') in 'Normalization organizes data to reduce redundancy. First normal form requires atomic values.' for e in c['evidence']) for c in d['data']['criterion_alignments']))"
echo "$AL" | py "('evidence sample', [c['evidence'][:1] for c in d['data']['criterion_alignments'] if c['evidence']][:2])"
echo "$AL" | py "('missing sample', d['data']['missing_elements'][:2])"
echo "$AL" | py "('method', d['data']['analysis_method'], d['data']['model_name'], d['data']['thresholds'])"
ALIGN_ID=$(echo "$AL" | py "d['data']['id']")
echo -n "completed re-request -> "; code POST "/student-answers/$ANS/rubric-alignment"; echo

echo "== Alignment changed nothing on the answer / submission"
api GET "/submissions/$SUB" | py "(d['data']['grading_status'], d['data']['awarded_marks'], d['data']['questions'][0]['answer']['awarded_marks'], d['data']['questions'][0]['answer']['rubric_alignment']['overall_alignment_score'], d['data']['questions'][0]['answer']['ai_grading'])"

echo "== STEP 27 AI grading alongside (different signal)"
api POST "/student-answers/$ANS/ai-grade" | py "d['data']['grading_status']"
echo -n "waiting for worker -> "; wait_status "/student-answers/$ANS/ai-grading" grading_status
api GET "/submissions/$SUB" | py "('ai suggested', d['data']['questions'][0]['answer']['ai_grading']['suggested_marks'], '/', d['data']['questions'][0]['answer']['ai_grading']['maximum_marks'], 'alignment', d['data']['questions'][0]['answer']['rubric_alignment']['overall_alignment_score'], '% -> different signals, faculty marks', d['data']['questions'][0]['answer']['awarded_marks'])"

echo "== Faculty review of alignment (no marks change)"
api POST "/rubric-alignments/$ALIGN_ID/review" | py "(d['data']['analysis_status'], d['data']['reviewed_by'] is not None)"
api GET "/submissions/$SUB" | py "('faculty marks still', d['data']['questions'][0]['answer']['awarded_marks'])"

echo "== Faculty final marks via STEP 27 stay separate"
api POST "/student-answers/$ANS/finalize-grade" '{"final_marks":6,"faculty_feedback":"Cover 2NF, 3NF and an example."}' | py "('faculty final', d['data']['awarded_marks'], 'ai suggested', d['data']['ai_grading']['suggested_marks'], 'alignment', d['data']['rubric_alignment']['overall_alignment_score'])"
docker compose exec -T mysql mysql -uroot -proot123 facultylens -e "SELECT sa.awarded_marks AS faculty_final, g.suggested_marks AS ai_suggested, a.overall_alignment_score AS alignment_pct, a.alignment_status, a.analysis_status, a.rubric_version, a.model_name FROM student_answers sa LEFT JOIN ai_grading_results g ON g.student_answer_id=sa.id AND g.is_current=1 LEFT JOIN answer_rubric_alignments a ON a.student_answer_id=sa.id AND a.is_current=1 WHERE sa.id=$ANS;" 2>/dev/null

echo "== Regenerate + history"
api POST "/rubric-alignments/$ALIGN_ID/regenerate" | py "(d['data']['analysis_status'], d['data']['id'] != $ALIGN_ID)"
echo -n "waiting for worker -> "; wait_status "/student-answers/$ANS/rubric-alignment" analysis_status
api GET "/student-answers/$ANS/rubric-alignment/history" | py "[(r['id'], r['analysis_status'], r['is_current'], r['overall_alignment_score']) for r in d['data']]"

echo "== Stale detection after answer edit"
api PUT "/student-answers/$ANS" '{"answer_text":"Normalization reduces redundancy."}' | py "d['data']['is_faculty_edited']"
api GET "/student-answers/$ANS/rubric-alignment" | py "(d['data']['is_stale'], d['data']['stale_reasons'])"

echo -n "audit entries -> "; docker compose exec -T mysql mysql -uroot -proot123 facultylens -N -e "SELECT GROUP_CONCAT(DISTINCT action ORDER BY action) FROM audit_logs WHERE action LIKE 'RUBRIC_ALIGNMENT%';" 2>/dev/null
echo -n "answer text in audit metadata -> "; docker compose exec -T mysql mysql -uroot -proot123 facultylens -N -e "SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'RUBRIC_ALIGNMENT%' AND metadata LIKE '%atomic values%';" 2>/dev/null

echo "== Cross-faculty access"
JAR2="$(mktemp)"; JAR_SAVE="$JAR"; JAR="$JAR2"
curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" "${ORIGIN[@]}" >/dev/null
api POST /auth/register "{\"name\":\"Other\",\"email\":\"other.$EMAIL\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"EEE\",\"designation\":\"Lecturer\"}" >/dev/null
api POST /auth/login "{\"email\":\"other.$EMAIL\",\"password\":\"$PASS\"}" >/dev/null
echo -n "other request alignment -> "; code POST "/student-answers/$ANS/rubric-alignment"; echo
echo -n "other view alignment    -> "; code GET "/student-answers/$ANS/rubric-alignment"; echo
echo -n "other regenerate        -> "; code POST "/rubric-alignments/$ALIGN_ID/regenerate"; echo
echo -n "other review            -> "; code POST "/rubric-alignments/$ALIGN_ID/review"; echo
echo -n "anonymous               -> "; curl -s -o /dev/null -w "%{http_code}\n" "$BASE/api/student-answers/$ANS/rubric-alignment" -H "Accept: application/json"
JAR="$JAR_SAVE"
echo "== E2E complete"
