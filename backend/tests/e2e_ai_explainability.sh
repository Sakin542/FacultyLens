#!/usr/bin/env bash
# STEP 45 E2E: faculty opens analysis -> views AI result -> opens explanation -> inspects evidence -> reviews limitations
#              -> overrides result -> audit event recorded. Also: authorization (outsider 403), similarity wording,
#              quality score consistency, recommendation traceability, review history, view-event audit.
set -euo pipefail
BASE="${BASE:-http://127.0.0.1:8080}"
JAR="$(mktemp)"
JAR2="$(mktemp)"
STAMP="$(date +%s)"
EMAIL="explain.e2e.$STAMP@university.edu"
EMAIL2="explain.outsider.$STAMP@university.edu"
PASS="Password123!"

api() { # method path [json] [jar]
  local m="$1" p="$2" d="${3:-}" jar="${4:-$JAR}"
  local xsrf; xsrf=$(grep XSRF-TOKEN "$jar" | awk '{print $7}' | python -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))")
  if [[ -n "$d" ]]; then
    curl -s -b "$jar" -c "$jar" -X "$m" "$BASE/api$p" -H "Accept: application/json" -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $xsrf" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" -d "$d"
  else
    curl -s -b "$jar" -c "$jar" -X "$m" "$BASE/api$p" -H "Accept: application/json" -H "X-XSRF-TOKEN: $xsrf" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/"
  fi
}
code() { # path [method] [json] [jar]
  local jar="${4:-$JAR}"
  local xsrf; xsrf=$(grep XSRF-TOKEN "$jar" | awk '{print $7}' | python -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))")
  if [[ -n "${3:-}" ]]; then
    curl -s -o /dev/null -w "%{http_code}" -b "$jar" -X "${2:-GET}" "$BASE/api$1" -H "Accept: application/json" -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $xsrf" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" -d "$3"
  else
    curl -s -o /dev/null -w "%{http_code}" -b "$jar" -X "${2:-GET}" "$BASE/api$1" -H "Accept: application/json" -H "X-XSRF-TOKEN: $xsrf" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/"
  fi
}
py() { python -c "import sys,json;d=json.load(sys.stdin);print($1)"; }
sql() { docker compose exec -T mysql mysql -uroot -proot123 facultylens -N -e "$1" 2>/dev/null | tr -d '\r'; }
expect() { if [[ "$2" == "$3" ]]; then echo "  ok   $1 = $2"; else echo "  FAIL $1: expected '$3' got '$2'"; exit 1; fi; }
expect_ge() { if (( $(python -c "print(1 if float('$2') >= float('$3') else 0)") )); then echo "  ok   $1 = $2 (>= $3)"; else echo "  FAIL $1: expected >= $3 got $2"; exit 1; fi; }
expect_not_contains() { if [[ "$2" != *"$3"* ]]; then echo "  ok   $1 does not contain '$3'"; else echo "  FAIL $1 contains '$3'"; exit 1; fi; }
login() { # email jar
  curl -s -c "$2" "$BASE/sanctum/csrf-cookie" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" >/dev/null
  api POST /auth/register "{\"name\":\"E2E Explain\",\"email\":\"$1\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"CSE\",\"designation\":\"Lecturer\"}" "$2" >/dev/null
  api POST /auth/login "{\"email\":\"$1\",\"password\":\"$PASS\"}" "$2" >/dev/null
}

echo "== 1. Faculty + outsider accounts"
login "$EMAIL" "$JAR"; login "$EMAIL2" "$JAR2"
expect "faculty logged in" "$(api GET /auth/user | py "d.get('user',d.get('data',{})).get('email','')")" "$EMAIL"

echo "== 2. Course -> LOs -> assessment -> questions -> previous question"
COURSE=$(api POST /courses '{"course_code":"CSE301","course_name":"Algorithms","semester":"Fall","academic_year":"2026","credits":3,"status":"active"}' | py "d['data']['id']")
LO1=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"LO1","description":"Understand graph traversal algorithms such as breadth-first and depth-first search.","cognitive_level":"Understand","sort_order":1}' | py "d['data']['id']")
LO2=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"LO2","description":"Apply database normalization concepts to relational schema design.","cognitive_level":"Apply","sort_order":2}' | py "d['data']['id']")
ASSESS=$(api POST "/courses/$COURSE/assessments" '{"title":"Algorithms Midterm","type":"midterm","total_marks":30,"duration_minutes":90,"status":"draft"}' | py "d['data']['id']")
docker compose exec -T app php artisan tinker --execute="
\$a=$ASSESS; foreach([['Compare breadth-first search and depth-first search and analyze their memory trade-offs.','medium','Understand',$LO1],['Define a binary heap.','easy','Remember',$LO1],['Design a fault-tolerant distributed cache and justify your architecture.','hard','Create',$LO2]] as \$i=>\$s){
\App\Models\Question::create(['assessment_id'=>\$a,'question_number'=>\$i+1,'question_text'=>\$s[0],'question_type'=>'descriptive','marks'=>10,'difficulty_level'=>\$s[1],'cognitive_level'=>\$s[2],'learning_outcome_id'=>\$s[3]]); }
\App\Models\PreviousQuestion::create(['user_id'=>\App\Models\Course::find($COURSE)->user_id,'course_id'=>$COURSE,'question_text'=>'Compare breadth-first search with depth-first search and discuss their memory usage.','question_type'=>'descriptive','marks'=>10,'source'=>'upload','source_year'=>'2025','source_assessment'=>'Midterm 2025']); echo 'ok';" | tr -dc 'a-z'; echo
Q1=$(sql "SELECT id FROM questions WHERE assessment_id=$ASSESS AND question_number=1")
echo "course=$COURSE assessment=$ASSESS q1=$Q1"

echo "== 3. Faculty opens analysis (live AI)"
AN=$(api POST /ai/analyze-assessment "{\"assessment_id\":$ASSESS}")
expect "analysis status" "$(echo "$AN" | py "d['status']")" "success"
expect "report persisted" "$(api GET "/ai/assessments/$ASSESS/analysis-status" | py "d['analysis_status']")" "completed"
REPORT=$(api GET "/ai/assessments/$ASSESS/analysis-status" | py "d['analysis_id']")
FULL=$(api GET "/ai/assessments/$ASSESS/analysis")
AI_BLOOM=$(echo "$FULL" | py "[q for q in d['data']['questions'] if q['id']==$Q1][0]['ai_cognitive_level']")
echo "  Q1 AI Bloom = $AI_BLOOM ; overall score = $(echo "$FULL" | py "d['data']['report']['overall_score']")"

echo "== 4. Views AI result -> opens explanation (Bloom)"
EX=$(api GET "/ai-results/bloom/$Q1/explanation")
expect "explanation ok" "$(echo "$EX" | py "d['status']")" "success"
expect "result label matches stored AI label" "$(echo "$EX" | py "d['data']['result']['label']")" "$(echo "$AI_BLOOM" | tr a-z A-Z)"
expect "method rule-based" "$(echo "$EX" | py "d['data']['method']['type']")" "RULE_BASED"
expect "confidence not fabricated" "$(echo "$EX" | py "d['data']['confidence']['available']")" "False"
expect "summary starts with FacultyLens" "$(echo "$EX" | py "d['data']['explanation']['summary'].startswith('FacultyLens')")" "True"
expect_not_contains "explanation" "$(echo "$EX" | python -c "import sys,json;print(json.dumps(json.load(sys.stdin)).lower())")" "system prompt"
expect_not_contains "explanation" "$(echo "$EX" | python -c "import sys,json;print(json.dumps(json.load(sys.stdin)).lower())")" "model thought"

echo "== 5. Inspects evidence (literal question excerpts)"
expect_ge "evidence items" "$(echo "$EX" | py "len(d['data']['evidence'])")" 1
expect "evidence is question wording" "$(echo "$EX" | py "all('compare' in e['text'].lower() or 'analyze' in e['text'].lower() or 'cue' in e['text'].lower() for e in d['data']['evidence'])")" "True"
expect "evidence status" "$(echo "$EX" | py "d['data']['evidence_status']")" "available"
expect "view event audited" "$(sql "SELECT COUNT(*) FROM audit_logs WHERE action='AI_EXPLANATION_VIEWED' AND entity_type='Question' AND entity_id=$Q1")" "1"
expect "evidence event accepted" "$(code "/ai-results/bloom/$Q1/events" POST '{"action":"AI_EVIDENCE_VIEWED","meta":{"level":3}}')" "202"
expect "evidence event audited" "$(sql "SELECT COUNT(*) FROM audit_logs WHERE action='AI_EVIDENCE_VIEWED' AND entity_id=$Q1")" "1"

echo "== 6. Reviews limitations + model/version metadata"
expect_ge "limitations shown" "$(echo "$EX" | py "len(d['data']['limitations'])")" 1
expect "analysis version context" "$(echo "$EX" | py "d['data']['related']['analysis_report_id']")" "$REPORT"
expect "rule version exposed" "$(echo "$EX" | py "bool(d['data']['model']['rule_version'])")" "True"
echo "  evaluation status: $(echo "$EX" | py "d['data']['evaluation']['label']")"

echo "== 7. Overrides result (faculty field changes, AI field untouched)"
OV=$(api POST "/ai-results/bloom/$Q1/override" '{"value":{"label":"EVALUATE"},"reason":"ACADEMIC_JUDGMENT","comment":"The question asks for a judgement of trade-offs."}')
expect "override ok" "$(echo "$OV" | py "d['status']")" "success"
expect "faculty cognitive_level" "$(sql "SELECT cognitive_level FROM questions WHERE id=$Q1")" "Evaluate"
expect "AI cognitive_level untouched" "$(sql "SELECT UPPER(ai_cognitive_level) FROM questions WHERE id=$Q1")" "$(echo "$AI_BLOOM" | tr a-z A-Z)"
expect "review recorded" "$(sql "SELECT action FROM ai_result_reviews WHERE ai_result_type='bloom' AND ai_result_id=$Q1 ORDER BY id DESC LIMIT 1")" "OVERRIDDEN"
expect "review keeps analysis version" "$(sql "SELECT analysis_report_id FROM ai_result_reviews WHERE ai_result_type='bloom' AND ai_result_id=$Q1 ORDER BY id DESC LIMIT 1")" "$REPORT"
expect "improvement signal" "$(sql "SELECT COUNT(*) FROM ai_improvement_signals WHERE signal_type='AI_RESULT_OVERRIDDEN' AND source='explainability' AND assessment_id=$ASSESS")" "1"
expect "audit event recorded" "$(sql "SELECT COUNT(*) FROM audit_logs WHERE action='AI_RESULT_OVERRIDDEN' AND entity_type='Question' AND entity_id=$Q1")" "1"
expect "audit visible in course activity" "$(api GET "/courses/$COURSE/collaboration/activity?per_page=50" | py "any(a['action']=='AI_RESULT_OVERRIDDEN' for a in d['data'])")" "True"
EX2=$(api GET "/ai-results/bloom/$Q1/explanation")
expect "latest review shown" "$(echo "$EX2" | py "d['data']['review']['latest']['action']")" "OVERRIDDEN"
expect "history count" "$(echo "$EX2" | py "d['data']['review']['history_count']")" "1"
expect "faculty value shown" "$(echo "$EX2" | py "d['data']['review']['faculty_value']")" "Evaluate"

echo "== 8. Quality score explanation is consistent with the stored score"
SCORE=$(echo "$FULL" | py "d['data']['report']['overall_score']")
QX=$(api GET "/ai-results/assessment_quality/$REPORT/explanation")
expect "quality score" "$(echo "$QX" | py "d['data']['result']['score']")" "$SCORE"
expect "score in summary" "$(echo "$QX" | py "str(round(d['data']['result']['score'],2)).rstrip('0').rstrip('.') in d['data']['explanation']['summary']")" "True"
expect "six dimensions" "$(echo "$QX" | py "len([e for e in d['data']['evidence'] if e['type']=='dimension'])")" "6"
expect "weights detail" "$(echo "$QX" | py "any(x['label']=='Weights' for x in d['data']['explanation']['details'])")" "True"
expect "review reviewed only" "$(echo "$QX" | py "d['data']['review']['actions']")" "['REVIEWED']"

echo "== 9. LO alignment + similarity explanations show real scores and threshold bands"
ALIGN_ID=$(sql "SELECT id FROM question_learning_outcome_alignments WHERE analysis_report_id=$REPORT AND question_id=$Q1 LIMIT 1")
if [[ -n "$ALIGN_ID" ]]; then
  AX=$(api GET "/ai-results/lo_alignment/$ALIGN_ID/explanation")
  SCOREA=$(sql "SELECT ROUND(similarity_score,2) FROM question_learning_outcome_alignments WHERE id=$ALIGN_ID")
  expect "alignment score displayed" "$(echo "$AX" | py "d['data']['result']['similarity_display']")" "$(python -c "print('%.2f / 1.00' % float('$SCOREA'))")"
  expect "alignment method" "$(echo "$AX" | py "d['data']['method']['type']")" "EMBEDDING_BASED"
  expect "threshold detail" "$(echo "$AX" | py "any('0.70' in str(x['value']) for x in d['data']['explanation']['details'] if x['label']=='Thresholds')")" "True"
  expect "alignment overridable to another LO" "$(echo "$AX" | py "len(d['data']['review']['override_options'])")" "2"
  OVA=$(api POST "/ai-results/lo_alignment/$ALIGN_ID/override" "{\"value\":{\"learning_outcome_id\":$LO2},\"reason\":\"COURSE_SPECIFIC_INTERPRETATION\"}")
  expect "LO override applied" "$(echo "$OVA" | py "d['data']['applied']['code']")" "LO2"
  expect "question faculty LO changed" "$(sql "SELECT learning_outcome_id FROM questions WHERE id=$Q1")" "$LO2"
  expect "AI alignment row untouched" "$(sql "SELECT learning_outcome_id FROM question_learning_outcome_alignments WHERE id=$ALIGN_ID")" "$LO1"
else
  echo "  skip alignment (no alignment row for Q1)"
fi
SIM_ID=$(sql "SELECT id FROM question_similarity_matches WHERE analysis_report_id=$REPORT ORDER BY similarity_score DESC LIMIT 1")
if [[ -n "$SIM_ID" ]]; then
  SX=$(api GET "/ai-results/similarity/$SIM_ID/explanation")
  expect "similarity method" "$(echo "$SX" | py "d['data']['method']['type']")" "EMBEDDING_BASED"
  expect "similarity display out of 1.00" "$(echo "$SX" | py "d['data']['result']['similarity_display'].endswith('/ 1.00')")" "True"
  expect_not_contains "similarity explanation" "$(echo "$SX" | python -c "import sys,json;print(json.dumps(json.load(sys.stdin)).lower())")" "exact duplicate"
  expect "previous question excerpt shown" "$(echo "$SX" | py "any('breadth' in (e.get('text') or '').lower() for e in d['data']['evidence'])")" "True"
  expect "similarity reject" "$(api POST "/ai-results/similarity/$SIM_ID/review" '{"action":"REJECTED","comment":"Different emphasis."}' | py "d['data']['review']['action']")" "REJECTED"
  expect "similarity audit" "$(sql "SELECT COUNT(*) FROM audit_logs WHERE action='AI_RESULT_REJECTED' AND entity_type='QuestionSimilarityMatch' AND entity_id=$SIM_ID")" "1"
else
  echo "  skip similarity (no match rows)"
fi

echo "== 10. Recommendation explanation traces its source; accept reuses STEP 20 feedback"
REC=$(sql "SELECT id FROM recommendations WHERE analysis_report_id=$REPORT ORDER BY id LIMIT 1")
if [[ -n "$REC" ]]; then
  RX=$(api GET "/ai-results/recommendation/$REC/explanation")
  expect "recommendation source detail" "$(echo "$RX" | py "any(x['label']=='Source' for x in d['data']['explanation']['details'])")" "True"
  expect "recommendation link to analysis" "$(echo "$RX" | py "any(l['type']=='analysis' for l in d['data']['related']['links'])")" "True"
  expect "recommendation accept" "$(api POST "/ai-results/recommendation/$REC/review" '{"action":"ACCEPTED"}' | py "d['data']['review']['action']")" "ACCEPTED"
  expect "recommendation status via STEP 20" "$(sql "SELECT LOWER(status) FROM recommendations WHERE id=$REC")" "accepted"
  expect "feedback row" "$(sql "SELECT COUNT(*) FROM recommendation_feedback WHERE recommendation_id=$REC")" "1"
else
  echo "  skip recommendation (none generated)"
fi

echo "== 11. Authorization: outsider cannot see explanations, evidence, or review"
expect "outsider explanation 403" "$(code "/ai-results/bloom/$Q1/explanation" GET "" "$JAR2")" "403"
expect "outsider quality 403" "$(code "/ai-results/assessment_quality/$REPORT/explanation" GET "" "$JAR2")" "403"
expect "outsider override 403" "$(code "/ai-results/bloom/$Q1/override" POST '{"value":{"label":"APPLY"},"reason":"OTHER"}' "$JAR2")" "403"
expect "outsider event 403" "$(code "/ai-results/bloom/$Q1/events" POST '{"action":"AI_RESULT_VIEWED"}' "$JAR2")" "403"
expect "unknown type 404" "$(code "/ai-results/system_prompt/$Q1/explanation")" "404"
expect "bad override value 422" "$(code "/ai-results/bloom/$Q1/override" POST '{"value":{"label":"GENIUS"},"reason":"OTHER"}')" "422"
expect "bad event 422" "$(code "/ai-results/bloom/$Q1/events" POST '{"action":"DROP_TABLE"}')" "422"
expect "outsider audit rows" "$(sql "SELECT COUNT(*) FROM audit_logs WHERE entity_id=$Q1 AND action LIKE 'AI_%' AND user_id=(SELECT id FROM users WHERE email='$EMAIL2')")" "0"

echo "== 12. Review history"
expect_ge "history entries" "$(api GET "/ai-results/bloom/$Q1/reviews" | py "len(d['data'])")" 1

echo
echo "STEP 45 explainability E2E passed."
