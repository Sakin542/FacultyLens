#!/usr/bin/env bash
# STEP 30 + 31 E2E: Program CSE (PO1..PO5) -> CSE101 with LO1..LO4 -> Midterm Q1..Q6 -> CO/PO matrix
#   -> students STU001..STU005 finalized grades -> Student Performance (Q/topic/LO) -> CO/PO analysis
#   -> findings, CO coverage+performance, PO evidence -> AI suggestion confirm -> stale -> cross-faculty.
# Verifies: only finalized grades count; no grades change; dashboards reflect DB values.
set -euo pipefail
BASE="${BASE:-http://127.0.0.1:8080}"
JAR="$(mktemp)"
STAMP=$(date +%s)
EMAIL="perf.e2e.$STAMP@university.edu"
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
tinker() { docker compose exec -T app php artisan tinker --execute="$1" | tr -dc '0-9,'; }

echo "== Register + login"
curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" "${ORIGIN[@]}" >/dev/null
api POST /auth/register "{\"name\":\"E2E Faculty\",\"email\":\"$EMAIL\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"CSE\",\"designation\":\"Lecturer\"}" | head -c 60; echo
api POST /auth/login "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}" | head -c 60; echo

echo "== Program CSE + PO1..PO5"
PROG=$(api POST /programs '{"code":"CSE","name":"Computer Science and Engineering"}' | py "d['data']['id']")
declare -A PO
for i in 1 2 3 4 5; do
  T=("" "Engineering Knowledge" "Problem Analysis" "Design / Development" "Investigation" "Modern Tool Usage")
  PO[$i]=$(api POST "/programs/$PROG/outcomes" "{\"code\":\"PO$i\",\"title\":\"${T[$i]}\"}" | py "d['data']['id']")
done
echo "program=$PROG POs=${PO[1]},${PO[2]},${PO[3]},${PO[4]},${PO[5]}"

echo "== Course CSE101 (assigned to CSE) + LO1..LO4 (act as CO1..CO4)"
COURSE=$(api POST /courses "{\"course_code\":\"CSE101\",\"course_name\":\"Database Systems\",\"semester\":\"Fall\",\"academic_year\":\"2026\",\"credits\":3,\"status\":\"active\",\"program_id\":$PROG}" | py "d['data']['id']")
declare -A CO
CO[1]=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"LO1","description":"Write SQL queries.","cognitive_level":"Apply","sort_order":1}' | py "d['data']['id']")
CO[2]=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"LO2","description":"Design normalized relational schemas.","cognitive_level":"Analyze","sort_order":2}' | py "d['data']['id']")
CO[3]=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"LO3","description":"Explain transaction isolation.","cognitive_level":"Understand","sort_order":3}' | py "d['data']['id']")
CO[4]=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"LO4","description":"Evaluate indexing strategies.","cognitive_level":"Evaluate","sort_order":4}' | py "d['data']['id']")
ASSESS=$(api POST "/courses/$COURSE/assessments" '{"title":"Midterm Examination","type":"midterm","total_marks":30,"status":"draft"}' | py "d['data']['id']")
# Q1 SQL(LO1) 10, Q2 Normalization(LO2) 10, Q3 Transactions(LO3) 10 ; Q4 unmapped bonus 2 (no LO)
QIDS=$(tinker "
\$a=$ASSESS;
\$q1=\App\Models\Question::create(['assessment_id'=>\$a,'question_number'=>1,'question_text'=>'Write a SQL join query.','question_type'=>'problem_solving','marks'=>10,'difficulty_level'=>'easy','cognitive_level'=>'Apply','learning_outcome_id'=>${CO[1]},'ai_topics'=>['SQL Queries']]);
\$q2=\App\Models\Question::create(['assessment_id'=>\$a,'question_number'=>2,'question_text'=>'Explain database normalization.','question_type'=>'descriptive','marks'=>10,'difficulty_level'=>'medium','cognitive_level'=>'Understand','learning_outcome_id'=>${CO[2]},'ai_topics'=>['Normalization']]);
\$q3=\App\Models\Question::create(['assessment_id'=>\$a,'question_number'=>3,'question_text'=>'Analyze transaction isolation levels.','question_type'=>'descriptive','marks'=>10,'difficulty_level'=>'hard','cognitive_level'=>'Analyze','learning_outcome_id'=>${CO[3]},'ai_topics'=>['Transactions']]);
\$q4=\App\Models\Question::create(['assessment_id'=>\$a,'question_number'=>4,'question_text'=>'Analyze the normalization problems in this schema.','question_type'=>'descriptive','marks'=>2,'cognitive_level'=>'Analyze']);
echo \$q1->id.','.\$q2->id.','.\$q3->id.','.\$q4->id;")
IFS=, read -r Q1 Q2 Q3 Q4 <<< "$QIDS"
echo "course=$COURSE assessment=$ASSESS questions=$Q1,$Q2,$Q3,$Q4"

echo "== CO -> PO matrix"
for m in "1 1 3" "1 2 2" "2 2 3" "2 3 2" "3 3 3" "3 4 2" "4 4 3" "4 5 1"; do
  set -- $m
  api POST "/courses/$COURSE/co-po-mappings" "{\"learning_outcome_id\":${CO[$1]},\"program_outcome_id\":${PO[$2]},\"mapping_level\":$3}" >/dev/null
done
echo -n "PO from another program -> "; OTHERP=$(api POST /programs '{"code":"EEE","name":"Electrical"}' | py "d['data']['id']"); FPO=$(api POST "/programs/$OTHERP/outcomes" '{"code":"PO1","title":"x"}' | py "d['data']['id']"); code POST "/courses/$COURSE/co-po-mappings" "{\"learning_outcome_id\":${CO[1]},\"program_outcome_id\":$FPO,\"mapping_level\":2}"; echo
api GET "/courses/$COURSE/co-po-mapping/matrix" | py "('active', d['data']['active_mappings'], '/', d['data']['possible_mappings'], 'density', d['data']['density_percent'], [[c['level'] for c in r['cells']] for r in d['data']['rows']])"

echo "== Students + finalized grades (5 students; Q1 ~80%, Q2 ~50%, Q3 ~60%) + 1 unfinalized"
SUBS=""
i=0
for row in "9 5 6" "8 6 7" "8 4 6" "7 5 5" "8 5 6"; do
  i=$((i+1)); set -- $row
  S=$(api POST /students "{\"student_identifier\":\"STU00$i\",\"name\":\"Student $i\"}" | py "d['data']['id']")
  SUB=$(api POST "/assessments/$ASSESS/submissions" "{\"student_id\":$S}" | py "d['data']['id']")
  A1=$(api POST "/submissions/$SUB/answers" "{\"question_id\":$Q1,\"answer_text\":\"SELECT ... JOIN ...\"}" | py "d['data']['id']")
  A2=$(api POST "/submissions/$SUB/answers" "{\"question_id\":$Q2,\"answer_text\":\"Normalization reduces redundancy.\"}" | py "d['data']['id']")
  A3=$(api POST "/submissions/$SUB/answers" "{\"question_id\":$Q3,\"answer_text\":\"Isolation levels ...\"}" | py "d['data']['id']")
  api POST "/student-answers/$A1/finalize-grade" "{\"final_marks\":$1}" >/dev/null
  api POST "/student-answers/$A2/finalize-grade" "{\"final_marks\":$2}" >/dev/null
  api POST "/student-answers/$A3/finalize-grade" "{\"final_marks\":$3}" >/dev/null
  SUBS="$SUBS $SUB"
done
S6=$(api POST /students '{"student_identifier":"STU006","name":"Student Six"}' | py "d['data']['id']")
SUB6=$(api POST "/assessments/$ASSESS/submissions" "{\"student_id\":$S6}" | py "d['data']['id']")
api POST "/submissions/$SUB6/answers" "{\"question_id\":$Q1,\"answer_text\":\"draft\",\"awarded_marks\":0}" >/dev/null   # graded via editor only -> IN_PROGRESS, excluded
docker compose exec -T mysql mysql -uroot -proot123 facultylens -e "SELECT grading_status, COUNT(*) n FROM student_submissions WHERE assessment_id=$ASSESS GROUP BY grading_status;" 2>/dev/null

echo "== STEP 30: Student performance"
echo -n "before analysis -> "; api GET "/assessments/$ASSESS/performance" | py "(d['data'], d['meta']['finalized_answer_count'])"
PERF=$(api POST "/assessments/$ASSESS/performance/analyze")
echo "$PERF" | py "('status', d['status'], d['data']['status'], 'students', d['data']['student_count'], 'finalized', d['data']['finalized_answer_count'], 'overall', d['data']['overall_average_percentage'], 'expected', d['data']['expected_performance_percent'], 'gap', d['data']['overall_gap'], d['data']['overall_status'])"
echo "$PERF" | py "[(('Q%s' % q['question_number']), q['average_percentage'], q['median_marks'], q['minimum_marks'], q['max_awarded_marks'], str(q['response_count'])+'/'+str(q['submission_count']), q['performance_gap'], q['performance_status'], q['difficulty_level']) for q in d['data']['questions']]"
echo "$PERF" | py "[(t['topic'], t['average_percentage'], t['performance_status']) for t in d['data']['topics']]"
echo "$PERF" | py "[(l['lo_code'], l['average_percentage'], l['performance_gap'], l['performance_status']) for l in d['data']['learning_outcomes']]"
echo "$PERF" | py "('gap areas', [(g['label'], g['performance_gap']) for g in d['data']['summary']['gap_areas']], 'strong', [(s['label'], s['average_percentage']) for s in d['data']['summary']['strong_areas']])"
echo "$PERF" | py "('Q1 signals', d['data']['questions'][0]['review_signals'])"
echo -n "duplicate analyze -> "; code POST "/assessments/$ASSESS/performance/analyze"; echo
docker compose exec -T mysql mysql -uroot -proot123 facultylens -e "SELECT q.question_number, ROUND(SUM(sa.awarded_marks)/(COUNT(*)*q.marks)*100,2) db_avg_pct, COUNT(*) n FROM student_answers sa JOIN student_submissions ss ON ss.id=sa.student_submission_id JOIN questions q ON q.id=sa.question_id WHERE ss.assessment_id=$ASSESS AND ss.grading_status IN ('FACULTY_REVIEWED','FINALIZED') AND sa.answer_status='REVIEWED' AND sa.awarded_marks IS NOT NULL GROUP BY q.id ORDER BY q.question_number;" 2>/dev/null
echo -n "student-level (STU001) -> "; FIRST=$(echo $SUBS | awk '{print $1}'); ST1=$(api GET "/submissions/$FIRST" | py "d['data']['student_id']"); api GET "/students/$ST1/assessments/$ASSESS/performance" | py "(d['data']['overall_percentage'], d['data']['finalized_question_count'], [(a['label'], a['percentage']) for a in d['data']['areas_for_review']])"
echo -n "no student identity in aggregate -> "; echo "$PERF" | python -c "import sys;t=sys.stdin.read();print('STU001' not in t and 'Student 1' not in t)"

echo "== STEP 31: CO/PO analysis"
CP=$(api POST "/courses/$COURSE/co-po-mapping/analyze")
echo "$CP" | py "('status', d['status'], d['data']['status'], 'summary', {k: d['data']['summary'][k] for k in ['co_count','po_count','active_mapping_count','mapping_density_percent','questions_mapped','question_count','cos_with_evidence','pos_with_evidence']})"
echo "$CP" | py "[(c['display_code'], c['coverage_percent'], c['performance_percent'], c['performance_gap'], c['status']) for c in d['data']['co_coverage']]"
echo "$CP" | py "[(p['code'], p['co_evidence'], p['assessment_evidence_percent'], p['contribution_percent'], p['student_performance_percent'], p['status']) for p in d['data']['po_evidence']]"
echo "$CP" | py "[(f['severity'], f['type'], f['title']) for f in d['data']['findings']]"
echo "$CP" | py "[c['label'] + (' OK' if c['ok'] else ' WARN') for c in d['data']['summary']['validation_checks']]"
echo -n "no accreditation claims -> "; echo "$CP" | python -c "import sys;t=sys.stdin.read().lower();print('compliant' not in t and 'accredited' not in t)"

echo "== AI suggestion (STEP 11 alignment) for unmapped Q4 -> confirm -> official"
REPORT=$(tinker "echo \App\Models\AnalysisReport::create(['assessment_id'=>$ASSESS,'analysis_status'=>'completed','analysis_version'=>1,'is_current'=>true])->id;")
tinker "\App\Models\QuestionLearningOutcomeAlignment::create(['analysis_report_id'=>$REPORT,'question_id'=>$Q4,'learning_outcome_id'=>${CO[2]},'similarity_score'=>0.82,'alignment'=>'STRONG_ALIGNMENT']); echo 1;" >/dev/null
api GET "/courses/$COURSE/co-po-mapping/question-mappings" | py "[(q['question_number'], q['is_mapped'], [(s['code'], s['similarity_score'], s['status'], s['mapping_source']) for s in q['ai_suggestions']]) for q in d['data'] if q['question_number']==4][0]"
api POST "/questions/$Q4/co-mappings/confirm" "{\"learning_outcome_id\":${CO[2]}}" | py "(d['data']['mapping_source'], d['data']['status'])"
api GET "/courses/$COURSE/co-po-mapping" | py "('stale after confirm', d['data']['current_run']['is_stale'], d['data']['summary']['questions_mapped'], '/', d['data']['summary']['question_count'])"
CP2=$(api POST "/courses/$COURSE/co-po-mapping/analyze")
echo "$CP2" | py "('after confirm: questions mapped', d['data']['summary']['questions_mapped'], 'CO2 coverage', [c['coverage_percent'] for c in d['data']['co_coverage'] if c['display_code']=='CO2'][0], 'unmapped findings', len([f for f in d['data']['findings'] if f['type']=='UNMAPPED_QUESTION']))"
docker compose exec -T mysql mysql -uroot -proot123 facultylens -e "SELECT id, status, is_current, JSON_EXTRACT(summary,'$.questions_mapped') mapped FROM co_po_mapping_analysis_runs WHERE course_id=$COURSE;" 2>/dev/null

echo "== Nothing changed grades"
docker compose exec -T mysql mysql -uroot -proot123 facultylens -N -e "SELECT CONCAT('sum awarded=', SUM(sa.awarded_marks), ' reviewed=', SUM(sa.answer_status='REVIEWED')) FROM student_answers sa JOIN student_submissions ss ON ss.id=sa.student_submission_id WHERE ss.assessment_id=$ASSESS;" 2>/dev/null
echo -n "audit -> "; docker compose exec -T mysql mysql -uroot -proot123 facultylens -N -e "SELECT GROUP_CONCAT(DISTINCT action ORDER BY action) FROM audit_logs WHERE action LIKE 'PERFORMANCE%' OR action LIKE 'STUDENT_PERFORMANCE%' OR action LIKE 'CO_PO%' OR action LIKE 'QUESTION_CO%' OR action LIKE 'PROGRAM%';" 2>/dev/null

echo "== Cross-faculty access"
JAR2="$(mktemp)"; JAR_SAVE="$JAR"; JAR="$JAR2"
curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" "${ORIGIN[@]}" >/dev/null
api POST /auth/register "{\"name\":\"Other\",\"email\":\"other.$EMAIL\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"EEE\",\"designation\":\"Lecturer\"}" >/dev/null
api POST /auth/login "{\"email\":\"other.$EMAIL\",\"password\":\"$PASS\"}" >/dev/null
echo -n "other performance      -> "; code GET "/assessments/$ASSESS/performance"; echo
echo -n "other student perf     -> "; code GET "/students/$ST1/assessments/$ASSESS/performance"; echo
echo -n "other co-po mapping    -> "; code GET "/courses/$COURSE/co-po-mapping"; echo
echo -n "other analyze co-po    -> "; code POST "/courses/$COURSE/co-po-mapping/analyze"; echo
echo -n "other add mapping      -> "; code POST "/courses/$COURSE/co-po-mappings" "{\"learning_outcome_id\":${CO[1]},\"program_outcome_id\":${PO[1]},\"mapping_level\":0}"; echo
echo -n "other program          -> "; code GET "/programs/$PROG"; echo
echo -n "anonymous              -> "; curl -s -o /dev/null -w "%{http_code}\n" "$BASE/api/assessments/$ASSESS/performance" -H "Accept: application/json"
JAR="$JAR_SAVE"
echo "== E2E complete"
