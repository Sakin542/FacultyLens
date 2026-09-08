#!/usr/bin/env bash
# STEP 26 E2E: Login -> CSE101 -> Midterm (Q1 5, Q2 10, Q3 15) -> students STU001-003 -> submissions -> answers -> refresh -> rubric link -> cross-faculty
set -euo pipefail
BASE="${BASE:-http://127.0.0.1:8080}"
JAR="$(mktemp)"
TMPD="$(mktemp -d)"; command -v cygpath >/dev/null && TMPD="$(cygpath -m "$TMPD")"
STAMP=$(date +%s)
EMAIL="sub.e2e.$STAMP@university.edu"
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
upload() { # path form-args...
  local p="$1"; shift
  curl -s -b "$JAR" -c "$JAR" -X POST "$BASE/api$p" -H "Accept: application/json" -H "X-XSRF-TOKEN: $(xsrf)" "${ORIGIN[@]}" "$@"
}
code() { curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -X "$1" "$BASE/api$2" -H "Accept: application/json" -H "X-XSRF-TOKEN: $(xsrf)" "${ORIGIN[@]}" ${3:+-H "Content-Type: application/json" -d "$3"}; }
py() { python -c "import sys,json;d=json.load(sys.stdin);print($1)"; }

echo "== Register + login"
curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" "${ORIGIN[@]}" >/dev/null
api POST /auth/register "{\"name\":\"E2E Faculty\",\"email\":\"$EMAIL\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"CSE\",\"designation\":\"Lecturer\"}" | head -c 120; echo
api POST /auth/login "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}" | head -c 80; echo

echo "== Course / LO / Assessment / Questions"
COURSE=$(api POST /courses '{"course_code":"CSE101","course_name":"Database Systems","semester":"Fall","academic_year":"2026","credits":3,"status":"active"}' | py "d['data']['id']")
LO=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"CO2","description":"Explain fundamental database concepts.","cognitive_level":"Understand","sort_order":1}' | py "d['data']['id']")
ASSESS=$(api POST "/courses/$COURSE/assessments" '{"title":"Midterm Examination","type":"midterm","total_marks":30,"status":"draft"}' | py "d['data']['id']")
QIDS=$(docker compose exec -T app php artisan tinker --execute="
\$a=$ASSESS; \$lo=$LO;
\$q1=\App\Models\Question::create(['assessment_id'=>\$a,'question_number'=>1,'question_text'=>'Define a primary key.','question_type'=>'short_answer','marks'=>5,'difficulty_level'=>'easy','cognitive_level'=>'Remember','learning_outcome_id'=>\$lo]);
\$q2=\App\Models\Question::create(['assessment_id'=>\$a,'question_number'=>2,'question_text'=>'Explain database normalization with suitable examples.','question_type'=>'descriptive','marks'=>10,'difficulty_level'=>'medium','cognitive_level'=>'Understand','learning_outcome_id'=>\$lo]);
\$q3=\App\Models\Question::create(['assessment_id'=>\$a,'question_number'=>3,'question_text'=>'Design an ER diagram for a library system.','question_type'=>'problem_solving','marks'=>15,'difficulty_level'=>'hard','cognitive_level'=>'Create','learning_outcome_id'=>\$lo]);
echo \$q1->id.','.\$q2->id.','.\$q3->id;" | tr -dc '0-9,')
IFS=, read -r Q1 Q2 Q3 <<< "$QIDS"
echo "course=$COURSE assessment=$ASSESS questions=$Q1,$Q2,$Q3"

echo "== Approve a rubric for Q2 (STEP 25) so availability shows on the submission"
RID=$(api POST "/questions/$Q2/rubrics/generate" | py "d['data']['id']")
api POST "/rubrics/$RID/approve" | py "d['data']['status']"

echo "== Students STU001-003"
S1=$(api POST /students '{"student_identifier":"STU001","name":"Student One","section":"A"}' | py "d['data']['id']")
S2=$(api POST /students '{"student_identifier":"STU002","name":"Student Two","section":"A"}' | py "d['data']['id']")
S3=$(api POST /students '{"student_identifier":"STU003","name":"Student Three","section":"B"}' | py "d['data']['id']")
echo -n "duplicate identifier -> "; code POST /students '{"student_identifier":"stu001","name":"Dup"}'; echo
api GET "/students" | py "d['meta']['total']"

echo "== Submissions"
SUB1=$(api POST "/assessments/$ASSESS/submissions" "{\"student_id\":$S1,\"submission_identifier\":\"MID-001\",\"submitted_at\":\"2026-09-08T10:30:00\"}" | py "d['data']['id']")
SUB2=$(api POST "/assessments/$ASSESS/submissions" "{\"student_id\":$S2,\"submission_identifier\":\"MID-002\"}" | py "d['data']['id']")
echo -n "duplicate submission -> "; code POST "/assessments/$ASSESS/submissions" "{\"student_id\":$S1}"; echo
echo "sub1=$SUB1 sub2=$SUB2"

echo "== Answers (text, marks validation, question mismatch, file)"
api POST "/submissions/$SUB1/answers" "{\"question_id\":$Q1,\"answer_text\":\"A primary key uniquely identifies each row.\"}" | py "(d['data']['answer_type'], d['data']['answer_status'])"
echo -n "marks > question max -> "; code POST "/submissions/$SUB1/answers" "{\"question_id\":$Q2,\"answer_text\":\"x\",\"awarded_marks\":15}"; echo
A2=$(api POST "/submissions/$SUB1/answers" "{\"question_id\":$Q2,\"answer_text\":\"Normalization is the process of organizing data to reduce redundancy.\"}" | py "d['data']['id']")
OTHER_ASSESS=$(api POST "/courses/$COURSE/assessments" '{"title":"Final","type":"final","total_marks":10,"status":"draft"}' | py "d['data']['id']")
FQ=$(docker compose exec -T app php artisan tinker --execute="echo \App\Models\Question::create(['assessment_id'=>$OTHER_ASSESS,'question_number'=>1,'question_text'=>'Other','question_type'=>'descriptive','marks'=>10])->id;" | tr -dc '0-9')
echo -n "question from another assessment -> "; code POST "/submissions/$SUB1/answers" "{\"question_id\":$FQ,\"answer_text\":\"x\"}"; echo
printf '%%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<</Root 1 0 R>>\n%%%%EOF\n' > "$TMPD/er.pdf"
upload "/submissions/$SUB1/answers" -F "question_id=$Q3" -F "file=@$TMPD/er.pdf;type=application/pdf" | py "(d['data']['answer_type'], d['data']['has_file'], d['data']['answer_file_name'])"
echo -n "executable upload -> "; printf 'MZ' > "$TMPD/x.exe"; upload "/submissions/$SUB2/answers" -F "question_id=$Q3" -F "file=@$TMPD/x.exe" -o /dev/null -w "%{http_code}"; echo

echo "== Edit answer (preserve original) + faculty marks"
api PUT "/student-answers/$A2" '{"answer_text":"Normalization organizes data to reduce redundancy and improve integrity.","awarded_marks":8,"faculty_feedback":"Good, mention anomalies."}' \
  | py "(d['data']['is_faculty_edited'], d['data']['original_answer_text'][:20], d['data']['awarded_marks'], d['data']['answer_status'])"

echo "== CSV import for STU003"
printf 'student_identifier,question_number,answer_text\nSTU003,1,"A key that uniquely identifies rows"\nSTU003,2,"Normalization..."\n' > "$TMPD/answers.csv"
upload "/assessments/$ASSESS/submissions/import" -F "file=@$TMPD/answers.csv;type=text/csv" | py "(d['data']['submissions_created'], d['data']['answers_created'])"
printf 'student_identifier,question_number,answer_text\nSTU999,1,"nobody"\n' > "$TMPD/bad.csv"
upload "/assessments/$ASSESS/submissions/import" -F "file=@$TMPD/bad.csv;type=text/csv" | py "(d['message'], d['errors'][0])"

echo "== Status workflow"
echo -n "SUBMITTED -> GRADED (invalid) -> "; code PATCH "/submissions/$SUB1/status" '{"status":"GRADED"}'; echo
api PATCH "/submissions/$SUB1/status" '{"status":"UNDER_REVIEW"}' | py "(d['data']['status'], d['data']['grading_status'])"

echo "== Refresh: list + summary + detail from MySQL"
api GET "/assessments/$ASSESS/submissions?per_page=2" | py "(d['meta']['total'], d['meta']['last_page'], [ (r['student']['student_identifier'], r['status'], r['answers_count'], r['awarded_marks']) for r in d['data']])"
api GET "/assessments/$ASSESS/submissions?status=UNDER_REVIEW" | py "d['meta']['total']"
api GET "/assessments/$ASSESS/submissions/summary" | py "(d['data']['total_submissions'], d['data']['total_answers'], d['data']['answers_by_status'])"
api GET "/submissions/$SUB1" | py "(d['data']['student']['name'], d['data']['awarded_marks'], d['data']['total_marks'], [(q['question_number'], q['answer'] is not None, q['approved_rubric'] is not None) for q in d['data']['questions']])"
docker compose exec -T mysql mysql -uroot -proot123 facultylens -e "SELECT id,student_id,status,grading_status,awarded_marks FROM student_submissions WHERE assessment_id=$ASSESS; SELECT student_submission_id,question_id,answer_type,is_faculty_edited,awarded_marks,answer_status FROM student_answers WHERE student_submission_id IN (SELECT id FROM student_submissions WHERE assessment_id=$ASSESS);" 2>/dev/null
echo -n "download file answer -> "; A3=$(api GET "/submissions/$SUB1" | py "[q['answer']['id'] for q in d['data']['questions'] if q['question_number']==3][0]"); curl -s -o /dev/null -w "%{http_code} %{content_type}\n" -b "$JAR" "$BASE/api/student-answers/$A3/download" "${ORIGIN[@]}"
echo -n "audit entries -> "; docker compose exec -T mysql mysql -uroot -proot123 facultylens -N -e "SELECT GROUP_CONCAT(DISTINCT action) FROM audit_logs WHERE action LIKE 'SUBMISSION%' OR action LIKE 'ANSWER%' OR action LIKE 'STUDENT%';" 2>/dev/null

echo "== Cross-faculty access"
JAR2="$(mktemp)"; JAR_SAVE="$JAR"; JAR="$JAR2"
curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" "${ORIGIN[@]}" >/dev/null
api POST /auth/register "{\"name\":\"Other\",\"email\":\"other.$EMAIL\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"EEE\",\"designation\":\"Lecturer\"}" >/dev/null
api POST /auth/login "{\"email\":\"other.$EMAIL\",\"password\":\"$PASS\"}" >/dev/null
echo -n "other list submissions -> "; code GET "/assessments/$ASSESS/submissions"; echo
echo -n "other view submission  -> "; code GET "/submissions/$SUB1"; echo
echo -n "other edit answer      -> "; code PUT "/student-answers/$A2" '{"answer_text":"tamper"}'; echo
echo -n "other download file    -> "; code GET "/student-answers/$A3/download"; echo
echo -n "other list students    -> "; api GET /students | py "d['meta']['total']"
echo -n "anonymous view         -> "; curl -s -o /dev/null -w "%{http_code}\n" "$BASE/api/submissions/$SUB1" -H "Accept: application/json"
JAR="$JAR_SAVE"
echo "== E2E complete"
