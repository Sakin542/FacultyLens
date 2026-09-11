#!/usr/bin/env bash
# STEP 37 E2E: Login -> CSE101 -> Midterm (50 marks / 90 min / 8 questions) -> Blueprint (30/50/20, CO 20/40/40, Bloom 20/40/30/10)
#              -> Validate -> Fix -> Finalize -> Generate questions (STEP 33) -> Compare actual questions -> real-data changes -> isolation
set -euo pipefail
BASE="${BASE:-http://127.0.0.1:8080}"
JAR="$(mktemp)"
EMAIL="blueprint.e2e.$(date +%s)@university.edu"
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
code() { curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -X "${2:-GET}" "$BASE/api$1" -H "Accept: application/json" -H "X-XSRF-TOKEN: $(grep XSRF-TOKEN "$JAR" | awk '{print $7}' | python -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))")" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/"; }

echo "== CSRF + register + login"
curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" >/dev/null
api POST /auth/register "{\"name\":\"E2E Blueprint\",\"email\":\"$EMAIL\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"CSE\",\"designation\":\"Lecturer\"}" | head -c 100; echo
api POST /auth/login "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}" | head -c 80; echo

echo "== CSE101 - Database Systems, 3 COs, Midterm 50 marks / 90 min"
COURSE=$(api POST /courses '{"course_code":"CSE101","course_name":"Database Systems","semester":"Fall","academic_year":"2026","credits":3,"status":"active"}' | py "d['data']['id']")
CO1=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"CO1","description":"Explain relational database concepts.","cognitive_level":"Understand","sort_order":1}' | py "d['data']['id']")
CO2=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"CO2","description":"Apply normalization to design schemas.","cognitive_level":"Apply","sort_order":2}' | py "d['data']['id']")
CO3=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"CO3","description":"Optimize SQL queries.","cognitive_level":"Analyze","sort_order":3}' | py "d['data']['id']")
ASSESS=$(api POST "/courses/$COURSE/assessments" '{"title":"Midterm Examination","type":"midterm","total_marks":50,"duration_minutes":90,"status":"draft"}' | py "d['data']['id']")
echo "course=$COURSE assessment=$ASSESS"
echo -n "no blueprint yet -> "; api GET "/assessments/$ASSESS/blueprint" | py "(d['data']['blueprint'], d['data']['permissions'])"

echo "== Create blueprint with an intentional error (Section B 2x7.5 -> 45 marks) and Bloom totalling 110%"
BAD=$(api POST "/assessments/$ASSESS/blueprint" "{\"title\":\"Midterm blueprint\",\"total_marks\":50,\"total_questions\":8,\"duration_minutes\":90,
 \"sections\":[{\"title\":\"Section A\",\"question_type\":\"mcq\",\"question_count\":4,\"marks_per_question\":2.5},{\"title\":\"Section B\",\"question_type\":\"problem_solving\",\"question_count\":2,\"marks_per_question\":7.5},{\"title\":\"Section C\",\"question_type\":\"descriptive\",\"question_count\":2,\"marks_per_question\":10}],
 \"constraints\":{\"difficulty\":[{\"key\":\"easy\",\"target_percentage\":30},{\"key\":\"medium\",\"target_percentage\":50},{\"key\":\"hard\",\"target_percentage\":20}],
  \"cognitive\":[{\"key\":\"Understand\",\"target_percentage\":30},{\"key\":\"Apply\",\"target_percentage\":40},{\"key\":\"Analyze\",\"target_percentage\":30},{\"key\":\"Evaluate\",\"target_percentage\":10}],
  \"learning_outcomes\":[{\"learning_outcome_id\":$CO1,\"target_percentage\":20},{\"learning_outcome_id\":$CO2,\"target_percentage\":40},{\"learning_outcome_id\":$CO3,\"target_percentage\":40}]}}")
BPID=$(echo "$BAD" | py "d['data']['blueprint']['id']")
echo "$BAD" | py "(d['data']['blueprint']['status'], d['data']['validation']['status'], [e['message'] for e in d['data']['validation']['errors']])"
echo -n "finalize INVALID -> "; code "/blueprints/$BPID/finalize" POST; echo

echo "== Fix: Section B 2x10, Bloom 20/40/30/10, add topics + plan rows -> VALID_WITH_WARNINGS (8 questions cannot represent 30/50/20 exactly)"
FIXED=$(api PUT "/blueprints/$BPID" "{\"title\":\"Midterm blueprint\",\"total_marks\":50,\"total_questions\":8,\"duration_minutes\":90,
 \"sections\":[{\"title\":\"Section A\",\"question_type\":\"mcq\",\"question_count\":4,\"marks_per_question\":2.5},{\"title\":\"Section B\",\"question_type\":\"problem_solving\",\"question_count\":2,\"marks_per_question\":10},{\"title\":\"Section C\",\"question_type\":\"descriptive\",\"question_count\":2,\"marks_per_question\":10}],
 \"constraints\":{\"difficulty\":[{\"key\":\"easy\",\"target_percentage\":30},{\"key\":\"medium\",\"target_percentage\":50},{\"key\":\"hard\",\"target_percentage\":20}],
  \"cognitive\":[{\"key\":\"Understand\",\"target_percentage\":20},{\"key\":\"Apply\",\"target_percentage\":40},{\"key\":\"Analyze\",\"target_percentage\":30},{\"key\":\"Evaluate\",\"target_percentage\":10}],
  \"learning_outcomes\":[{\"learning_outcome_id\":$CO1,\"target_percentage\":20},{\"learning_outcome_id\":$CO2,\"target_percentage\":40},{\"learning_outcome_id\":$CO3,\"target_percentage\":40}],
  \"topics\":[{\"topic\":\"Normalization\",\"target_count\":4,\"target_marks\":25},{\"topic\":\"SQL\",\"target_count\":4,\"target_marks\":25}]},
 \"items\":[{\"section_order\":1,\"learning_outcome_id\":$CO1,\"question_type\":\"mcq\",\"difficulty_level\":\"easy\",\"cognitive_level\":\"Understand\",\"question_count\":2,\"marks_each\":2.5,\"topic\":\"SQL\"},
  {\"section_order\":1,\"learning_outcome_id\":$CO2,\"question_type\":\"mcq\",\"difficulty_level\":\"medium\",\"cognitive_level\":\"Apply\",\"question_count\":2,\"marks_each\":2.5,\"topic\":\"Normalization\"},
  {\"section_order\":2,\"learning_outcome_id\":$CO2,\"question_type\":\"problem_solving\",\"difficulty_level\":\"medium\",\"cognitive_level\":\"Analyze\",\"question_count\":2,\"marks_each\":10,\"topic\":\"Normalization\"},
  {\"section_order\":3,\"learning_outcome_id\":$CO3,\"question_type\":\"descriptive\",\"difficulty_level\":\"hard\",\"cognitive_level\":\"Evaluate\",\"question_count\":2,\"marks_each\":10,\"topic\":\"SQL\"}]}")
echo "$FIXED" | py "(d['data']['blueprint']['status'], d['data']['validation']['status'], d['data']['validation']['completeness']['score'], d['data']['validation']['time_indicator']['minutes_per_mark'])"
echo "$FIXED" | py "'warnings: ' + ' | '.join(w['message'] for w in d['data']['validation']['warnings'])"
echo "$FIXED" | py "'difficulty allocation: ' + str([(r['label'], r['target_percentage'], r['derived_count']) for r in d['data']['coverage']['distributions']['difficulty']['rows']])"
echo "$FIXED" | py "'CO x difficulty: ' + str({r['label']: r['cells'] for r in d['data']['coverage']['matrices']['co_x_difficulty']['rows']})"
echo "$FIXED" | py "'PO: ' + str(d['data']['coverage']['distributions']['program_outcomes'].get('message'))"

echo "== Finalize"
api POST "/blueprints/$BPID/finalize" | py "(d['data']['blueprint']['status'], d['data']['blueprint']['finalized_at'] is not None)"
echo -n "delete finalized -> "; code "/blueprints/$BPID" DELETE; echo

echo "== Generate questions from blueprint (STEP 33; drafts only)"
GEN=$(api POST "/blueprints/$BPID/generate-questions" '{"language":"English"}')
echo "$GEN" | py "[(r['id'], r['learning_outcome_id'], r['topic'], r['question_type'], r['number_of_questions'], r['generation_status']) for r in d['data']['requests']]"
REQ=$(echo "$GEN" | py "d['data']['requests'][0]['id']")
for i in $(seq 1 30); do ST=$(api GET "/question-generation/$REQ" | py "d['data']['generation_status']" 2>/dev/null || echo PENDING); [[ "$ST" == "COMPLETED" || "$ST" == "FAILED" ]] && break; sleep 2; done
echo "first request status=$ST"; api GET "/question-generation/$REQ" | py "[(q['question_type'], q['difficulty_level'], q['cognitive_level'], q['marks'], q['review_status']) for q in d['data'].get('questions') or []]" 2>/dev/null || true
echo -n "official questions in assessment (must still be 0) -> "; api GET "/assessments/$ASSESS" | py "len(d['data'].get('questions') or [])"

echo "== Faculty adds the actual question set (matching the plan) then compares"
docker compose exec -T app php artisan tinker --execute="
\$a=$ASSESS; \$spec=[['mcq',2.5,'easy','Understand',$CO1,['SQL']],['mcq',2.5,'easy','Understand',$CO1,['SQL']],['mcq',2.5,'medium','Apply',$CO2,['Normalization']],['mcq',2.5,'medium','Apply',$CO2,['Normalization']],
['problem_solving',10,'medium','Analyze',$CO2,['Normalization']],['problem_solving',10,'medium','Analyze',$CO2,['Normalization']],['descriptive',10,'hard','Evaluate',$CO3,['SQL']],['descriptive',10,'hard','Evaluate',$CO3,['SQL']]];
foreach(\$spec as \$i=>\$s){ \App\Models\Question::create(['assessment_id'=>\$a,'question_number'=>\$i+1,'question_text'=>'Blueprint question '.(\$i+1),'question_type'=>\$s[0],'marks'=>\$s[1],'difficulty_level'=>\$s[2],'cognitive_level'=>\$s[3],'learning_outcome_id'=>\$s[4],'ai_topics'=>\$s[5]]); } echo 'ok';" | tr -dc 'a-z'; echo
CMP=$(api GET "/blueprints/$BPID/comparison")
echo "$CMP" | py "'compliance=%s summary=%s structure=%s' % (d['data']['compliance_percent'], d['data']['summary'], [(s['label'], s['actual'], s['target'], s['status']) for s in d['data']['structure']])"
echo "$CMP" | py "'difficulty: ' + str([(r['label'], r['target_percentage'], r['actual_percentage'], r['status']) for r in d['data']['dimensions']['difficulty']['rows']])"
echo "$CMP" | py "'CO: ' + str([(r['label'], r['target_percentage'], r['actual_percentage'], r['status']) for r in d['data']['dimensions']['learning_outcomes']['rows']])"

echo "== REAL-DATA: Q3 Medium -> Hard and Q5 CO2 -> CO3; comparison must change"
docker compose exec -T app php artisan tinker --execute="\$q=\App\Models\Question::where('assessment_id',$ASSESS)->orderBy('question_number')->get(); \$q[2]->update(['difficulty_level'=>'hard']); \$q[4]->update(['learning_outcome_id'=>$CO3]); echo 'ok';" | tr -dc 'a-z'; echo
CMP2=$(api GET "/blueprints/$BPID/comparison?sync_recommendations=1")
echo "$CMP2" | py "'compliance=%s summary=%s' % (d['data']['compliance_percent'], d['data']['summary'])"
echo "$CMP2" | py "'difficulty: ' + str([(r['label'], r['actual_percentage'], r['status']) for r in d['data']['dimensions']['difficulty']['rows']])"
echo "$CMP2" | py "'CO: ' + str([(r['label'], r['actual_percentage'], r['status']) for r in d['data']['dimensions']['learning_outcomes']['rows']])"
echo "$CMP2" | py "'recommendations sync: ' + str(d['data']['recommendations_sync'])"
echo -n "questions unchanged by comparison (still 8, Q3 hard) -> "; docker compose exec -T mysql mysql -uroot -proot123 facultylens -N -e "SELECT COUNT(*), SUM(difficulty_level='hard') FROM questions WHERE assessment_id=$ASSESS;" 2>/dev/null | tr '\t' '/'

echo "== Validate individual questions against plan rows"
QIDS=$(docker compose exec -T mysql mysql -uroot -proot123 facultylens -N -e "SELECT GROUP_CONCAT(id) FROM questions WHERE assessment_id=$ASSESS;" 2>/dev/null | tr -d '\r')
api POST "/blueprints/$BPID/validate-questions" "{\"question_ids\":[$QIDS]}" | py "('matched %s of %s' % (d['data']['matched'], d['data']['evaluated']), [(r['id'], r['status'], r['failed_constraints']) for r in d['data']['results'] if r['status'] != 'MATCH'])"

echo "== Editing a finalized blueprint creates version 2; v1 stays intact"
V2=$(api PUT "/blueprints/$BPID" '{"title":"Midterm blueprint v2","total_marks":60,"total_questions":10,"duration_minutes":90,"sections":[{"title":"Only","question_type":"descriptive","question_count":10,"marks_per_question":6}],"constraints":{"difficulty":[{"key":"easy","target_percentage":30},{"key":"medium","target_percentage":50},{"key":"hard","target_percentage":20}]},"items":[]}')
echo "$V2" | py "(d['message'], d['data']['blueprint']['version'], d['data']['blueprint']['status'], [(v['version'], v['status'], v['total_marks']) for v in d['data']['versions']])"

echo "== Analytics card + audit"
api GET "/analytics/overview?course_id=$COURSE&fresh=1" | py "(d['data']['blueprint_compliance']['assessments_with_blueprint'], [(r['assessment_title'], r['version'], r['compliance_percent']) for r in d['data']['blueprint_compliance']['rows']])"
docker compose exec -T mysql mysql -uroot -proot123 facultylens -e "SELECT action, COUNT(*) c FROM audit_logs WHERE action LIKE 'BLUEPRINT%' AND user_id=(SELECT id FROM users WHERE email='$EMAIL') GROUP BY action;" 2>/dev/null

echo "== Cross-faculty isolation"
JAR2="$(mktemp)"; JAR_SAVE="$JAR"; JAR="$JAR2"
curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" >/dev/null
api POST /auth/register "{\"name\":\"Other\",\"email\":\"other.$EMAIL\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"EEE\",\"designation\":\"Lecturer\"}" >/dev/null
api POST /auth/login "{\"email\":\"other.$EMAIL\",\"password\":\"$PASS\"}" >/dev/null
echo -n "other GET blueprint  -> "; code "/assessments/$ASSESS/blueprint"; echo
echo -n "other PUT blueprint  -> "; code "/blueprints/$BPID" PUT; echo
echo -n "other finalize       -> "; code "/blueprints/$BPID/finalize" POST; echo
echo -n "other comparison     -> "; code "/blueprints/$BPID/comparison"; echo
JAR="$JAR_SAVE"
echo -n "anonymous            -> "; curl -s -o /dev/null -w "%{http_code}\n" "$BASE/api/assessments/$ASSESS/blueprint" -H "Accept: application/json"
echo "== E2E complete"
