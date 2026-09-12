#!/usr/bin/env bash
# STEP 41 — Golden Path against the running Docker stack (Laravel :8080, MySQL, FastAPI, queue worker).
# Every step goes through the public HTTP API exactly as the frontend does; nothing is created via tinker.
#
#   register/login -> course CSE-401 -> LO1..LO3 -> program + POs + CO/PO mapping + analysis
#   -> Midterm (100 marks / 120 min) -> AI question generation -> approve -> add 8 official questions
#   -> blueprint create/validate/finalize -> live AI analysis -> recommendation decision
#   -> AI rubric + approval -> version v1.0 finalize (immutable) -> 6 students via CSV import
#   -> finalize-grade 48 answers -> GRADED -> performance analysis -> analytics overview
#   -> reports PDF/CSV/XLSX (preview -> generate -> download -> verify bytes) -> privacy -> audit -> DB integrity
#
# Exit code is non-zero on the first failed expectation. Usage: bash backend/tests/e2e_golden_path.sh
set -euo pipefail
BASE="${BASE:-http://127.0.0.1:8080}"
JAR="$(mktemp)"; JAR2="$(mktemp)"
STAMP="$(date +%s)"
EMAIL="golden.$STAMP@university.edu"; EMAIL2="golden.other.$STAMP@university.edu"
PASS="GoldenPath#2026"
TMPD=$(cygpath -m "$(mktemp -d)" 2>/dev/null || mktemp -d)
FAILS=0

api() { # method path [json] [jar]
  local m="$1" p="$2" d="${3:-}" jar="${4:-$JAR}"
  local xsrf; xsrf=$(grep XSRF-TOKEN "$jar" | awk '{print $7}' | python -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))")
  if [[ -n "$d" ]]; then
    curl -s -b "$jar" -c "$jar" -X "$m" "$BASE/api$p" -H "Accept: application/json" -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $xsrf" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" -d "$d"
  else
    curl -s -b "$jar" -c "$jar" -X "$m" "$BASE/api$p" -H "Accept: application/json" -H "X-XSRF-TOKEN: $xsrf" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/"
  fi
}
upload() { # path curl-file-args...
  local p="$1"; shift
  local xsrf; xsrf=$(grep XSRF-TOKEN "$JAR" | awk '{print $7}' | python -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))")
  curl -s -b "$JAR" -c "$JAR" -X POST "$BASE/api$p" -H "Accept: application/json" -H "X-XSRF-TOKEN: $xsrf" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" "$@"
}
py() { python -c "import sys,json;d=json.load(sys.stdin);print($1)"; }
code() { curl -s -o /dev/null -w "%{http_code}" -b "${2:-$JAR}" "$BASE/api$1" -H "Accept: application/json" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/"; }
expect() { # label actual expected
  if [[ "$2" == "$3" ]]; then echo "  ✓ $1: $2"; else echo "  ✗ $1: expected [$3] got [$2]"; FAILS=$((FAILS+1)); fi; }
expect_ge() { # label actual min
  if python -c "import sys; sys.exit(0 if float('$2') >= float('$3') else 1)"; then echo "  ✓ $1: $2 (>= $3)"; else echo "  ✗ $1: $2 < $3"; FAILS=$((FAILS+1)); fi; }
sql() { docker compose exec -T mysql mysql -uroot -proot123 facultylens -N -s -e "$1" 2>/dev/null | tr -d '\r'; }
login() { # email jar name
  curl -s -c "$2" "$BASE/sanctum/csrf-cookie" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" >/dev/null
  api POST /auth/register "{\"name\":\"$3\",\"email\":\"$1\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"CSE\",\"designation\":\"Professor\"}" "$2" | py "d.get('message')"
  api POST /auth/login "{\"email\":\"$1\",\"password\":\"$PASS\"}" "$2" | py "d.get('message')"
}

echo "== 0. Stack readiness"
curl -s "$BASE/api/health/ready" | py "(d['status'], d['components'])"
expect "AI service reachable" "$(curl -s http://127.0.0.1:8001/health | py "d['status']")" "ok"
integrity() { docker compose exec -T app php artisan facultylens:integrity-check --json 2>/dev/null | python -c "import sys,json;print(json.load(sys.stdin).get('issues'))"; }
INTEG_BEFORE=$(integrity); echo "  integrity issues on this database before the run: $INTEG_BEFORE"

echo "== 1. Register + login (faculty A) and a second faculty (B) for isolation checks"
login "$EMAIL" "$JAR" "Dr. Ada Lovelace"
login "$EMAIL2" "$JAR2" "Dr. Other Faculty"
USER_ID=$(api GET /auth/user | py "d['user']['id']")

echo "== 2. Course CSE-401 + LO1..LO3"
COURSE=$(api POST /courses "{\"course_code\":\"CSE-401-$STAMP\",\"course_name\":\"Artificial Intelligence\",\"semester\":\"Fall\",\"academic_year\":\"2026\",\"credits\":3,\"status\":\"active\"}" | py "d['data']['id']")
LO1=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"LO1","description":"Explain uninformed and informed search strategies and their complexity.","cognitive_level":"Understand","sort_order":1}' | py "d['data']['id']")
LO2=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"LO2","description":"Apply knowledge representation techniques to model a problem domain.","cognitive_level":"Apply","sort_order":2}' | py "d['data']['id']")
LO3=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"LO3","description":"Evaluate machine learning models using appropriate metrics.","cognitive_level":"Evaluate","sort_order":3}' | py "d['data']['id']")
expect "learning outcomes" "$(api GET "/courses/$COURSE/learning-outcomes" | py "len(d['data'])")" "3"

echo "== 3. Program outcomes + CO/PO mapping + analysis"
PROG=$(api POST /programs "{\"code\":\"BSC-CSE-$STAMP\",\"name\":\"B.Sc. in CSE\"}" | py "d['data']['id']")
api PUT "/courses/$COURSE" "{\"program_id\":$PROG}" | py "'  course -> program: ' + str(d.get('data',{}).get('program_id'))"
PO1=$(api POST "/programs/$PROG/outcomes" '{"code":"PO1","title":"Engineering knowledge"}' | py "d['data']['id']")
PO2=$(api POST "/programs/$PROG/outcomes" '{"code":"PO2","title":"Problem analysis"}' | py "d['data']['id']")
for m in "$LO1 $PO1 3" "$LO2 $PO2 2" "$LO3 $PO2 3"; do set -- $m; api POST "/courses/$COURSE/co-po-mappings" "{\"learning_outcome_id\":$1,\"program_outcome_id\":$2,\"mapping_level\":$3}" >/dev/null; done
expect "CO/PO analysis" "$(api POST "/courses/$COURSE/co-po-mapping/analyze" | py "d['status']")" "success"

echo "== 4. Midterm (100 marks, 120 min) + 8 official questions via AI generation -> approval -> add"
ASSESS=$(api POST "/courses/$COURSE/assessments" '{"title":"Midterm Examination","type":"midterm","total_marks":100,"duration_minutes":120,"status":"draft","assessment_date":"2026-10-15"}' | py "d['data']['id']")
QIDS=()
gen_add() { # lo_id topic type difficulty cognitive marks count
  local REQ; REQ=$(api POST /question-generation "{\"course_id\":$COURSE,\"assessment_id\":$ASSESS,\"topic\":\"$2\",\"learning_outcome_id\":$1,\"question_type\":\"$3\",\"difficulty_level\":\"$4\",\"cognitive_level\":\"$5\",\"marks\":$6,\"number_of_questions\":$7,\"include_expected_answer\":true}")
  local RID; RID=$(echo "$REQ" | py "d['data']['id']")
  local ST; ST=$(echo "$REQ" | py "d['data']['generation_status']")
  for _ in $(seq 1 60); do [[ "$ST" == "COMPLETED" || "$ST" == "FAILED" ]] && break; sleep 1; ST=$(api GET "/question-generation/$RID" | py "d['data']['generation_status']"); done
  expect "generation $2" "$ST" "COMPLETED"
  for GQ in $(api GET "/question-generation/$RID" | py "' '.join(str(q['id']) for q in d['data']['questions'][:$7])"); do
    api POST "/generated-questions/$GQ/approve" '{"note":"Golden path"}' >/dev/null
    QIDS+=("$(api POST "/generated-questions/$GQ/add-to-assessment" | py "d['data']['question']['id']")")
  done
}
gen_add "$LO1" "Search algorithms" descriptive easy Understand 10 3
gen_add "$LO2" "Knowledge representation" problem_solving medium Apply 15 3
gen_add "$LO3" "Model evaluation metrics" descriptive hard Evaluate 12.5 2
expect "official questions" "${#QIDS[@]}" "8"
expect "question marks sum" "$(sql "SELECT SUM(marks) FROM questions WHERE assessment_id=$ASSESS")" "100.00"
QLIST=$(IFS=,; echo "${QIDS[*]}")

echo "== 5. Blueprint create -> validate -> finalize"
BP=$(api POST "/assessments/$ASSESS/blueprint" "{\"title\":\"Midterm blueprint\",\"total_marks\":100,\"total_questions\":8,\"duration_minutes\":120,
 \"sections\":[{\"title\":\"Search\",\"question_type\":\"descriptive\",\"question_count\":3,\"marks_per_question\":10},{\"title\":\"KR\",\"question_type\":\"problem_solving\",\"question_count\":3,\"marks_per_question\":15},{\"title\":\"ML\",\"question_type\":\"descriptive\",\"question_count\":2,\"marks_per_question\":12.5}],
 \"constraints\":{\"difficulty\":[{\"key\":\"easy\",\"target_percentage\":30},{\"key\":\"medium\",\"target_percentage\":45},{\"key\":\"hard\",\"target_percentage\":25}],
  \"learning_outcomes\":[{\"learning_outcome_id\":$LO1,\"target_percentage\":30},{\"learning_outcome_id\":$LO2,\"target_percentage\":45},{\"learning_outcome_id\":$LO3,\"target_percentage\":25}]},\"items\":[]}")
BPID=$(echo "$BP" | py "d['data']['blueprint']['id']")
echo "  validation: $(api POST "/blueprints/$BPID/validate" | py "d['data']['validation']['status'] if 'validation' in d['data'] else d['data'].get('status')")"
expect "blueprint finalized" "$(api POST "/blueprints/$BPID/finalize" | py "d['data']['blueprint']['status']")" "FINALIZED"
api POST "/blueprints/$BPID/validate-questions" "{\"question_ids\":[$QLIST]}" | py "'  question check: matched %s of %s' % (d['data']['matched'], d['data']['evaluated'])"

echo "== 6. Live AI analysis"
AN=$(api POST /ai/analyze-assessment "{\"assessment_id\":$ASSESS}")
expect "analysis status" "$(echo "$AN" | py "d['status']")" "success"
echo "$AN" | py "'  overall quality: %s (%s)' % (d['data']['quality_analysis']['overall_quality_score'], d['data']['quality_analysis'].get('quality_rating'))"
expect "report persisted" "$(api GET "/ai/assessments/$ASSESS/analysis-status" | py "d['analysis_status']")" "completed"
expect "questions classified" "$(sql "SELECT COUNT(*) FROM questions WHERE assessment_id=$ASSESS AND ai_analysis_status='completed'")" "8"

echo "== 7. Recommendations -> faculty decision"
RECS=$(api GET "/ai/assessments/$ASSESS/recommendations")
NREC=$(echo "$RECS" | py "len(d['data']['recommendations'] if isinstance(d['data'],dict) and 'recommendations' in d['data'] else d['data'])")
expect_ge "recommendations" "$NREC" 1
REC=$(echo "$RECS" | py "(d['data']['recommendations'] if isinstance(d['data'],dict) and 'recommendations' in d['data'] else d['data'])[0]['id']")
api POST "/recommendations/$REC/feedback" '{"decision":"ACCEPTED","usefulness_rating":4,"comment":"Will rebalance."}' | py "'  ' + d['message']"
expect "decision persisted" "$(sql "SELECT LOWER(status) FROM recommendations WHERE id=$REC")" "accepted"
expect_ge "feedback listed" "$(api GET /feedback | py "len(d['data'])")" 1

echo "== 8. AI rubric -> approve (question ${QIDS[3]})"
RUB=$(api POST "/questions/${QIDS[3]}/rubrics/generate")
RID=$(echo "$RUB" | py "d['data']['id']")
expect "rubric draft" "$(echo "$RUB" | py "d['data']['status']")" "DRAFT"
echo "$RUB" | py "'  criteria: %d, sum max_marks: %s' % (len(d['data']['criteria']), sum(float(c['max_marks']) for c in d['data']['criteria']))"
expect "rubric approved" "$(api POST "/rubrics/$RID/approve" | py "d['data']['status']")" "APPROVED"

echo "== 9. Version v1.0 -> finalize -> immutable"
V1=$(api POST "/assessments/$ASSESS/versions" '{"change_summary":"Initial paper"}' | py "d['data']['version']['id']")
expect "v1 finalized" "$(api POST "/assessment-versions/$V1/finalize" | py "d['data']['version']['status']")" "FINALIZED"
expect "snapshot questions" "$(sql "SELECT COUNT(*) FROM assessment_version_questions WHERE assessment_version_id=$V1")" "8"
expect "edit finalized -> 409" "$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -X PUT "$BASE/api/assessment-versions/$V1" -H "Accept: application/json" -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $(grep XSRF-TOKEN "$JAR" | awk '{print $7}' | python -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))")" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" -d '{"title":"tamper"}')" "409"

echo "== 10. Six students -> CSV import (48 answers) -> finalize grades -> GRADED"
# per-question maxima: Q1-3 = 10, Q4-6 = 15, Q7-8 = 12.5
MARKS=("8 9 7 6 5 4 3 9" "10 7 9 8 12 9 10 11" "6 5 9 5 8 6 7 6" "9 8 9 9 13 8 12 12" "4 3 5 2 6 3 4 5" "7 6 10 7 10 7 9 10")
{ echo "student_identifier,question_number,answer_text"
  for s in 1 2 3 4 5 6; do for q in 1 2 3 4 5 6 7 8; do echo "GP-STU-00$s,$q,\"Answer of student $s to question $q.\""; done; done; } > "$TMPD/answers.csv"
for s in 1 2 3 4 5 6; do api POST /students "{\"student_identifier\":\"GP-STU-00$s\",\"name\":\"Student $s\",\"section\":\"A\"}" >/dev/null; done
IMP=$(upload "/assessments/$ASSESS/submissions/import" -F "file=@$TMPD/answers.csv;type=text/csv")
expect "submissions created" "$(echo "$IMP" | py "d['data']['submissions_created']")" "6"
expect "answers created" "$(echo "$IMP" | py "d['data']['answers_created']")" "48"
TOTAL=0
for s in 1 2 3 4 5 6; do
  read -ra M <<< "${MARKS[$((s-1))]}"
  SUB=$(sql "SELECT ss.id FROM student_submissions ss JOIN students st ON st.id=ss.student_id WHERE ss.assessment_id=$ASSESS AND st.student_identifier='GP-STU-00$s'")
  api PATCH "/submissions/$SUB/status" '{"status":"UNDER_REVIEW"}' >/dev/null
  for q in 0 1 2 3 4 5 6 7; do
    A=$(sql "SELECT id FROM student_answers WHERE student_submission_id=$SUB AND question_id=${QIDS[$q]}")
    expect "finalize Q$((q+1)) student $s = ${M[$q]}" "$(api POST "/student-answers/$A/finalize-grade" "{\"final_marks\":${M[$q]}}" | py "d.get('status','error')")" "success"
    TOTAL=$((TOTAL+M[q]))
  done
  api PATCH "/submissions/$SUB/status" '{"status":"GRADED"}' >/dev/null
done
expect "graded submissions" "$(sql "SELECT COUNT(*) FROM student_submissions WHERE assessment_id=$ASSESS AND status='GRADED'")" "6"
expect "awarded total (sum of all marks = $TOTAL)" "$(sql "SELECT CAST(SUM(awarded_marks) AS SIGNED) FROM student_submissions WHERE assessment_id=$ASSESS")" "$TOTAL"

echo "== 11. Performance analysis"
PERF=$(api POST "/assessments/$ASSESS/performance/analyze")
expect "performance status" "$(echo "$PERF" | py "d['data']['status']")" "COMPLETED"
expect "finalized answers counted" "$(echo "$PERF" | py "d['data']['finalized_answer_count']")" "48"
EXPECTED_AVG=$(python -c "print(round($TOTAL/600*100,1))")
echo "  overall average: $(echo "$PERF" | py "d['data']['overall_average_percentage']") (expected ≈ $EXPECTED_AVG)"
expect "LO results" "$(api GET "/assessments/$ASSESS/performance/learning-outcomes" | py "len(d['data'])")" "3"

echo "== 12. Analytics overview reflects the data"
OV=$(api GET "/analytics/overview?fresh=1")
expect "courses KPI" "$(echo "$OV" | py "d['data']['kpis']['courses']['value']")" "1"
expect "questions KPI" "$(echo "$OV" | py "d['data']['kpis']['questions']['value']")" "8"
expect "analyzed assessments" "$(echo "$OV" | py "d['data']['assessment_quality']['analyzed_assessments']")" "1"
expect "performance available" "$(echo "$OV" | py "d['data']['performance']['available']")" "True"

echo "== 13. Reports: preview -> PDF / CSV / XLSX -> download -> verify"
F="{\"course_id\":$COURSE,\"assessment_id\":$ASSESS}"
expect_ge "preview records" "$(api POST /reports/preview "{\"report_type\":\"ASSESSMENT_QUALITY\",\"scope_type\":\"ASSESSMENT\",\"filters\":$F}" | py "d['data']['record_count']")" 1
gen_report() { # type format -> id
  local R; R=$(api POST /reports "{\"report_type\":\"$1\",\"scope_type\":\"ASSESSMENT\",\"filters\":$F,\"format\":\"$2\"}")
  local ID; ID=$(echo "$R" | py "d['data']['id']")
  for _ in $(seq 1 40); do local ST; ST=$(api GET "/reports/$ID" | py "d['data']['status']"); [[ "$ST" == "COMPLETED" || "$ST" == "FAILED" ]] && break; sleep 1; done
  expect "$1/$2 completed" "$(api GET "/reports/$ID" | py "d['data']['status']")" "COMPLETED"
  echo "$ID"
}
PDF=$(gen_report ASSESSMENT_QUALITY PDF | tail -1); CSV=$(gen_report QUESTION_ANALYSIS CSV | tail -1); XLSX=$(gen_report STUDENT_PERFORMANCE XLSX | tail -1)
curl -s -b "$JAR" -o "$TMPD/r.pdf" "$BASE/api/reports/$PDF/download" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/"
curl -s -b "$JAR" -o "$TMPD/r.csv" "$BASE/api/reports/$CSV/download" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/"
curl -s -b "$JAR" -o "$TMPD/r.xlsx" "$BASE/api/reports/$XLSX/download" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/"
expect "PDF magic" "$(head -c 5 "$TMPD/r.pdf")" "%PDF-"
expect "CSV has question text" "$(grep -c "$(sql "SELECT LEFT(question_text,30) FROM questions WHERE id=${QIDS[0]}")" "$TMPD/r.csv" | awk '{print ($1>0)?"yes":"no"}')" "yes"
expect "XLSX is a zip workbook" "$(head -c 2 "$TMPD/r.xlsx")" "PK"
expect "performance export has no student identity" "$(python -c "import zipfile;z=zipfile.ZipFile('$TMPD/r.xlsx');t=''.join(z.read(n).decode('utf8','ignore') for n in z.namelist() if n.endswith('.xml'));print('GP-STU-001' in t or 'Student 1' in t)")" "False"

echo "== 14. Privacy / isolation (faculty B)"
expect "B cannot read A's assessment" "$(code "/assessments/$ASSESS" "$JAR2")" "403"
expect "B cannot read A's course" "$(code "/courses/$COURSE" "$JAR2")" "403"
B_DL=$(code "/reports/$PDF/download" "$JAR2"); [[ "$B_DL" == "403" || "$B_DL" == "404" ]] && echo "  ✓ B cannot download A's report: $B_DL" || { echo "  ✗ B download -> $B_DL"; FAILS=$((FAILS+1)); }
expect "B sees no courses" "$(api GET /courses "" "$JAR2" | py "len(d['data'])")" "0"

echo "== 15. Audit trail + database integrity"
sql "SELECT action, COUNT(*) FROM audit_logs WHERE user_id=$USER_ID GROUP BY action ORDER BY action" | sed 's/^/  /'
for a in AI_ANALYSIS_STARTED AI_ANALYSIS_COMPLETED REPORT_GENERATION_COMPLETED REPORT_DOWNLOADED; do expect_ge "audit $a" "$(sql "SELECT COUNT(*) FROM audit_logs WHERE user_id=$USER_ID AND action='$a'")" 1; done
INTEG_AFTER=$(integrity)
expect "no new integrity issues introduced by this run (before=$INTEG_BEFORE)" "$INTEG_AFTER" "$INTEG_BEFORE"

echo
if [[ $FAILS -eq 0 ]]; then echo "== GOLDEN PATH PASSED (course=$COURSE assessment=$ASSESS user=$EMAIL)"; else echo "== GOLDEN PATH FAILED: $FAILS expectation(s)"; exit 1; fi
