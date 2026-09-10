#!/usr/bin/env bash
# STEP 33 E2E: register -> CSE101 (+CO2, program/PO2) -> Midterm with Q1 -> previous question -> upload syllabus (indexed)
# -> generate 3 constrained drafts -> validation/similarity -> edit -> approve -> add to assessment -> official question
# -> negative (weak alignment stays draft) -> duplicate flagged but kept -> cross-user 403 -> no auto-publish.
set -euo pipefail
BASE="${BASE:-http://127.0.0.1:8080}"
JAR="$(mktemp)"
STAMP="$(date +%s)"
EMAIL="qgen.e2e.$STAMP@university.edu"
PASS="Password123!"
TMP="$(mktemp -d)"; command -v cygpath >/dev/null && TMP="$(cygpath -m "$TMP")"
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
py() { python -c "import sys,json;d=json.load(sys.stdin);print($1)"; }
login() { rm -f "$JAR"; JAR="$(mktemp)"; curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" "${ORIGIN[@]}" >/dev/null
  api POST /auth/register "{\"name\":\"E2E Faculty\",\"email\":\"$1\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"CSE\",\"designation\":\"Lecturer\"}" >/dev/null
  api POST /auth/login "{\"email\":\"$1\",\"password\":\"$PASS\"}" | head -c 100; echo; }
wait_status() { # path key wanted...
  local p="$1" k="$2"; shift 2
  for i in $(seq 1 40); do
    local st; st=$(api GET "$p" | py "d['data']['$k']")
    for w in "$@"; do [[ "$st" == "$w" ]] && { echo "$p -> $st"; return 0; }; done
    sleep 3
  done
  echo "timeout waiting on $p ($st)"; return 1
}

cat > "$TMP/syllabus.txt" <<'EOF'
Unit 2 Normalization
Normalization is the process of organizing relational schemas to reduce data redundancy and avoid update anomalies. First normal form requires atomic attribute values. Second normal form removes partial dependencies on a composite key. Third normal form removes transitive dependencies. Boyce-Codd normal form is a stricter variant of third normal form.
Unit 3 Transactions
A transaction must satisfy the ACID properties. Two-phase locking guarantees serializability.
Unit 4 Indexing
A B-tree index keeps keys sorted and supports range scans.
EOF

echo "== Faculty A"
login "$EMAIL"
PROG=$(api POST /programs '{"code":"CSE","name":"Computer Science"}' | py "d['data']['id']")
PO=$(api POST "/programs/$PROG/outcomes" '{"code":"PO2","title":"Problem Analysis","description":"Identify and analyse complex engineering problems."}' | py "d['data']['id']")
COURSE=$(api POST /courses "{\"course_code\":\"CSE101\",\"course_name\":\"Database Systems\",\"semester\":\"Fall\",\"academic_year\":\"2026\",\"credits\":3,\"status\":\"active\",\"program_id\":$PROG}" | py "d['data']['id']")
CO=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"CO2","description":"Analyze database structures and identify normalization issues.","cognitive_level":"Analyze","sort_order":2}' | py "d['data']['id']")
ASSESS=$(api POST "/courses/$COURSE/assessments" '{"title":"Midterm Examination","type":"midterm","total_marks":30,"status":"draft"}' | py "d['data']['id']")
Q1=$(docker compose exec -T app php artisan tinker --execute="echo \App\Models\Question::create(['assessment_id'=>$ASSESS,'question_number'=>1,'question_text'=>'Explain the process of converting a relation into Third Normal Form.','question_type'=>'descriptive','marks'=>10,'difficulty_level'=>'medium','cognitive_level'=>'Understand','learning_outcome_id'=>$CO])->id;" | tr -dc '0-9')
api POST "/courses/$COURSE/previous-questions" '{"question_text":"Define functional dependency with an example.","question_type":"short_answer","marks":5,"source":"previous_exam","source_year":"2025"}' >/dev/null
echo "program=$PROG po=$PO course=$COURSE co=$CO assessment=$ASSESS q1=$Q1"

echo "== Upload + index syllabus"
DOC=$(curl -s -b "$JAR" -c "$JAR" -X POST "$BASE/api/documents" -H "Accept: application/json" -H "X-XSRF-TOKEN: $(xsrf)" "${ORIGIN[@]}" -F "course_id=$COURSE" -F "document_type=syllabus" -F "file=@$TMP/syllabus.txt;type=text/plain" | py "d['data']['id']")
wait_status "/documents/$DOC" indexing_status INDEXED

echo "== Generate 3 constrained drafts (Normalization / CO2 / PO2 / descriptive / medium / Analyze / 10 marks)"
REQ=$(api POST /question-generation "{\"course_id\":$COURSE,\"assessment_id\":$ASSESS,\"topic\":\"Normalization\",\"learning_outcome_id\":$CO,\"program_outcome_id\":$PO,\"question_type\":\"descriptive\",\"difficulty_level\":\"medium\",\"cognitive_level\":\"Analyze\",\"marks\":10,\"number_of_questions\":3,\"include_expected_answer\":true}")
RID=$(echo "$REQ" | py "d['data']['id']")
echo "$REQ" | py "(d['data']['generation_status'], d['data']['warnings'])"
wait_status "/question-generation/$RID" generation_status COMPLETED FAILED
R=$(api GET "/question-generation/$RID")
echo "$R" | py "(d['data']['generation_method'], d['data']['models'], d['data']['retrieved_chunks'], d['data']['existing_questions_count'])"
echo "$R" | python -c "
import sys,json;d=json.load(sys.stdin)['data']
assert d['generation_status']=='COMPLETED', d['error_message']
assert len(d['questions'])==3, len(d['questions'])
for q in d['questions']:
    v=q['validation']
    print(f\"Q{q['sequence']} [{q['review_status']}/{q['validation_status']}] {q['question_text'][:110]}\")
    print('   type', q['question_type'], 'det', v['detected_question_type'], '| diff', q['difficulty_level'], 'det', v['detected_difficulty'], '| bloom', q['cognitive_level'], 'det', v['detected_cognitive_level'])
    print('   CO', v['co_alignment_status'], v['co_alignment_score'], '| sim', v['similarity_status'], v['max_similarity_score'], '| warnings', len(v['warnings']))
    assert 'normalization' in q['question_text'].lower(), 'off-topic draft'
    assert q['review_status']=='DRAFT' and q['official_question_id'] is None
print('set_summary:', d['set_summary'])
"
docker compose exec -T mysql mysql -uroot -proot123 facultylens -e "SELECT COUNT(*) official_questions FROM questions WHERE assessment_id=$ASSESS;" 2>/dev/null
QID=$(echo "$R" | py "d['data']['questions'][0]['id']")
QID2=$(echo "$R" | py "d['data']['questions'][1]['id']")

echo "== Draft cannot be added before approval"
api POST "/generated-questions/$QID/add-to-assessment" | head -c 160; echo

echo "== Edit (original preserved) -> approve -> add to assessment"
api PUT "/generated-questions/$QID" '{"question_text":"Analyze the following order-processing schema, identify every normalization anomaly it exhibits, and explain the trade-offs of decomposing it into third normal form.","marks":10}' | py "(d['data']['version'], d['data']['review_status'], d['data']['is_edited'], d['data']['original_question_text'][:60])"
api POST "/generated-questions/$QID/approve" '{"note":"Good draft"}' | py "(d['data']['review_status'], d['data']['can_add_to_assessment'])"
ADD=$(api POST "/generated-questions/$QID/add-to-assessment")
echo "$ADD" | py "(d['message'], d['data']['question']['question_number'], d['data']['question']['marks'], d['data']['generated_question']['official_question_id'])"
docker compose exec -T mysql mysql -uroot -proot123 facultylens -e "SELECT id,question_number,question_type,marks,difficulty_level,cognitive_level,learning_outcome_id,ai_cognitive_level FROM questions WHERE assessment_id=$ASSESS;" 2>/dev/null
api POST "/generated-questions/$QID/add-to-assessment" | head -c 120; echo

echo "== Regenerate one draft with feedback (history kept)"
NEW=$(api POST "/generated-questions/$QID2/regenerate" '{"feedback":["too_similar","poor_wording"],"feedback_note":"Use a university enrolment schema"}')
echo "$NEW" | py "(d['data']['sequence'], d['data']['regenerated_from_id'], d['data']['review_status'], d['data']['question_text'][:100])"
api GET "/question-generation/$RID" | py "[(q['sequence'], q['review_status']) for q in d['data']['questions']]"

echo "== Duplicate detection: ask for an UNDERSTAND/descriptive question on 3NF conversion (Q1 already exists)"
REQ2=$(api POST /question-generation "{\"course_id\":$COURSE,\"assessment_id\":$ASSESS,\"topic\":\"Third Normal Form\",\"learning_outcome_id\":$CO,\"question_type\":\"descriptive\",\"cognitive_level\":\"Understand\",\"marks\":10,\"number_of_questions\":2}")
RID2=$(echo "$REQ2" | py "d['data']['id']")
wait_status "/question-generation/$RID2" generation_status COMPLETED FAILED
api GET "/question-generation/$RID2" | python -c "
import sys,json;d=json.load(sys.stdin)['data']
for q in d['questions']:
    v=q['validation']; print(f\"Q{q['sequence']} sim={v['similarity_status']} {v['max_similarity_score']} match={[ (m['label'], m['similarity_score']) for m in v['similar_questions'][:1]]} status={q['validation_status']} review={q['review_status']}\")
    assert q['review_status']=='DRAFT'   # flagged, never auto-rejected
"

echo "== Negative: unrelated topic vs CO2 -> weak/no alignment warning, stays draft"
REQ3=$(api POST /question-generation "{\"course_id\":$COURSE,\"topic\":\"Kirchhoff circuit laws\",\"learning_outcome_id\":$CO,\"question_type\":\"short_answer\",\"cognitive_level\":\"Remember\",\"marks\":2,\"number_of_questions\":1}")
RID3=$(echo "$REQ3" | py "d['data']['id']")
wait_status "/question-generation/$RID3" generation_status COMPLETED FAILED
api GET "/question-generation/$RID3" | py "(d['data']['questions'][0]['validation']['co_alignment_status'], d['data']['questions'][0]['validation']['warnings'], d['data']['questions'][0]['review_status'])"

echo "== Validation & limits"
api POST /question-generation "{\"course_id\":$COURSE,\"question_type\":\"descriptive\",\"marks\":10,\"number_of_questions\":21}" | head -c 140; echo
api POST /question-generation "{\"course_id\":$COURSE,\"question_type\":\"descriptive\",\"marks\":0,\"number_of_questions\":1}" | head -c 140; echo

echo "== Audit"
docker compose exec -T mysql mysql -uroot -proot123 facultylens -e "SELECT action, COUNT(*) FROM audit_logs WHERE action LIKE 'QUESTION_%' GROUP BY action;" 2>/dev/null

echo "== Faculty B: cross-user blocked"
login "qgen.e2e.b.$STAMP@university.edu"
api POST /question-generation "{\"course_id\":$COURSE,\"question_type\":\"descriptive\",\"marks\":10,\"number_of_questions\":1}" | head -c 120; echo
api GET "/question-generation/$RID" | head -c 120; echo
api POST "/generated-questions/$QID2/approve" | head -c 120; echo
api PUT "/generated-questions/$QID2" '{"marks":1}' | head -c 120; echo
api GET /question-generation | py "len(d['data'])"
echo "== DONE"
rm -rf "$TMP" "$JAR"
