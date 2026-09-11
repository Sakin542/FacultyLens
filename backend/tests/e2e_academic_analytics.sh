#!/usr/bin/env bash
# STEP 36 E2E: Login -> CSE101 -> assessment + questions -> real AI analysis -> finalized grades -> STEP 30 run
#              -> /api/analytics overview -> filters -> sections -> real-data verification (Medium -> Hard) -> compare -> export -> isolation
set -euo pipefail
BASE="${BASE:-http://127.0.0.1:8080}"
JAR="$(mktemp)"
EMAIL="analytics.e2e.$(date +%s)@university.edu"
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
code() { curl -s -o /dev/null -w "%{http_code}" -b "$JAR" "$BASE/api$1" -H "Accept: application/json" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/"; }

echo "== CSRF + register + login"
curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" >/dev/null
api POST /auth/register "{\"name\":\"E2E Analytics\",\"email\":\"$EMAIL\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"CSE\",\"designation\":\"Lecturer\"}" | head -c 120; echo
api POST /auth/login "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}" | head -c 80; echo

echo "== Analytics before any data: N/A everywhere, no fake numbers"
api GET /analytics/overview | py "(d['data']['kpis']['courses']['value'], d['data']['kpis']['average_quality']['value'], d['data']['kpis']['student_performance']['value'], d['data']['performance']['available'], d['data']['attention_areas'])"

echo "== CSE101 - Database Systems: 3 COs, midterm with 10 questions (Easy 3 / Medium 5 / Hard 2)"
COURSE=$(api POST /courses '{"course_code":"CSE101","course_name":"Database Systems","semester":"Fall","academic_year":"2026","credits":3,"status":"active"}' | py "d['data']['id']")
LO1=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"CO1","description":"Explain relational database concepts, keys and constraints.","cognitive_level":"Understand","sort_order":1}' | py "d['data']['id']")
LO2=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"CO2","description":"Apply normalization to design relational schemas.","cognitive_level":"Apply","sort_order":2}' | py "d['data']['id']")
LO3=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"CO3","description":"Optimize SQL queries using indexing and query plans.","cognitive_level":"Analyze","sort_order":3}' | py "d['data']['id']")
ASSESS=$(api POST "/courses/$COURSE/assessments" '{"title":"Midterm Examination","type":"midterm","total_marks":100,"status":"draft","assessment_date":"2026-10-01"}' | py "d['data']['id']")
QIDS=$(docker compose exec -T app php artisan tinker --execute="
\$a=$ASSESS; \$ids=[];
\$spec=[['Define a primary key and a foreign key.','easy','Remember',$LO1],['List the ACID properties of a transaction.','easy','Remember',$LO1],['State the purpose of database normalization.','easy','Understand',$LO2],
['Explain the difference between 2NF and 3NF with an example.','medium','Understand',$LO2],['Describe how a B+ tree index speeds up range queries.','medium','Understand',$LO3],['Apply normalization to decompose a given relation into 3NF.','medium','Apply',$LO2],
['Write an SQL query with a join and a GROUP BY for the given schema.','medium','Apply',$LO1],['Apply indexing to improve a slow query and justify the index choice.','medium','Apply',$LO3],
['Critically evaluate two concurrency control protocols for a high-contention OLTP workload.','hard','Evaluate',$LO3],['Design a normalized schema for a university and prove it is in BCNF.','hard','Create',$LO2]];
foreach(\$spec as \$i=>\$s){ \$ids[]=\App\Models\Question::create(['assessment_id'=>\$a,'question_number'=>\$i+1,'question_text'=>\$s[0],'question_type'=>'descriptive','marks'=>10,'difficulty_level'=>\$s[1],'cognitive_level'=>\$s[2],'learning_outcome_id'=>\$s[3]])->id; }
echo implode(',', \$ids);" | tr -dc '0-9,')
IFS=, read -r Q1 Q2 Q3 Q4 Q5 Q6 Q7 Q8 Q9 Q10 <<< "$QIDS"
echo "course=$COURSE assessment=$ASSESS questions=$QIDS"

echo "== Real AI assessment analysis (Laravel -> FastAPI -> analysis_reports / alignments / similarity / recommendations)"
api POST /ai/analyze-assessment "{\"assessment_id\":$ASSESS}" | py "(d['status'], d['data'].get('report',{}).get('overall_score') if isinstance(d.get('data'),dict) else None)"

echo "== Finalized faculty grades: 4 submissions, Q1 marks 8/7/6/9 of 10 (expected average 75%)"
MARKS=(8 7 6 9)
for i in 1 2 3 4; do
  M=${MARKS[$((i-1))]}
  S=$(api POST /students "{\"student_identifier\":\"STU00$i\",\"name\":\"Student $i\",\"section\":\"A\"}" | py "d['data']['id']")
  SUB=$(api POST "/assessments/$ASSESS/submissions" "{\"student_id\":$S,\"submission_identifier\":\"MID-00$i\"}" | py "d['data']['id']")
  A=$(api POST "/submissions/$SUB/answers" "{\"question_id\":$Q1,\"answer_text\":\"A primary key uniquely identifies a row; a foreign key references it.\"}" | py "d['data']['id']")
  api PATCH "/submissions/$SUB/status" '{"status":"UNDER_REVIEW"}' >/dev/null
  api PUT "/student-answers/$A" "{\"awarded_marks\":$M,\"faculty_feedback\":\"ok\"}" >/dev/null
  api PATCH "/submissions/$SUB/status" '{"status":"GRADED"}' | py "(d['data']['status'], d['data']['grading_status'])"
done

echo "== STEP 30 performance analysis"
api POST "/assessments/$ASSESS/performance/analyze" | py "(d['status'], d['data'].get('status'), d['data'].get('overall_average_percentage'))"

echo "== Analytics overview (all sections from real records)"
OV=$(api GET "/analytics/overview?fresh=1")
echo "$OV" | py "'kpis: ' + str({k:v['value'] for k,v in d['data']['kpis'].items()})"
echo "$OV" | py "'quality: analyzed=%s avg=%s counts=%s' % (d['data']['assessment_quality']['analyzed_assessments'], d['data']['assessment_quality']['average_score'], d['data']['assessment_quality']['counts'])"
echo "$OV" | py "'difficulty: ' + str([(x['level'], x['count'], x['percentage'], x['difference']) for x in d['data']['difficulty']['distribution']]) + ' status=' + str(d['data']['difficulty']['balance_status'])"
echo "$OV" | py "'bloom: ' + str([(x['level'], x['count'], x['percentage']) for x in d['data']['cognitive']['distribution']])"
echo "$OV" | py "'CO coverage: ' + str([(o['code'], o['questions'], o['strong'], o['weak'], o['not_aligned'], o['coverage_percentage'], o['status']) for o in d['data']['learning_outcomes']['outcomes']]) + ' overall=' + str(d['data']['learning_outcomes']['coverage_percentage'])"
echo "$OV" | py "'PO: configured=%s %s' % (d['data']['program_outcomes']['configured'], d['data']['program_outcomes'].get('message'))"
echo "$OV" | py "'performance: avg=%s median=%s min=%s max=%s submissions=%s responses=%s status=%s' % tuple(d['data']['performance'][k] for k in ('average_percentage','median_percentage','minimum_percentage','maximum_percentage','submissions','responses','status'))"
echo "$OV" | py "'gaps: ' + str(d['data']['learning_gaps']['counts']) + ' top=' + str([(g['code'], g['average_percentage'], g['gap'], g['status']) for g in d['data']['learning_gaps']['top_gaps']])"
echo "$OV" | py "'questions: ' + str([(q['question_number'], q['average_percentage'], q['responses'], q['status']) for q in d['data']['question_performance']][:5])"
echo "$OV" | py "'similarity: ' + str({k:v['questions'] for k,v in d['data']['similarity']['by_status'].items()})"
echo "$OV" | py "'recommendations: active=%s accepted=%s dismissed=%s review=%s' % (d['data']['recommendations']['active'], d['data']['recommendations']['accepted'], d['data']['recommendations']['dismissed'], d['data']['recommendations']['under_review'])"
echo "$OV" | py "'ai evaluation: evaluated_tasks=%s' % d['data']['ai_evaluation']['evaluated_tasks']"
echo "$OV" | py "'attention: ' + str([(a['severity'], a['type'], a['title']) for a in d['data']['attention_areas']])"
echo "$OV" | py "'meta: cached=%s generated=%s' % (d['data']['meta']['cached'], d['data']['meta']['generated_at'])"

echo "== Filters apply to every section"
api GET "/analytics/filters" | py "([c['code'] for c in d['data']['courses']], d['data']['semesters'], d['data']['academic_years'], d['data']['assessment_types'])"
api GET "/analytics/overview?course_id=$COURSE&semester=Fall&academic_year=2026&assessment_type=midterm" | py "(d['data']['kpis']['assessments']['value'], d['data']['kpis']['questions']['value'], d['data']['difficulty']['total_questions'])"
api GET "/analytics/overview?assessment_type=final" | py "(d['data']['kpis']['assessments']['value'], d['data']['kpis']['questions']['value'], d['data']['assessment_quality']['analyzed_assessments'], d['data']['performance']['available'])"
api GET "/analytics/overview?start_date=2026-11-01" | py "d['data']['kpis']['assessments']['value']"
api GET "/analytics/courses/$COURSE/performance" | py "sorted(d['data'].keys())"
api GET "/analytics/courses/$COURSE/history" | py "[(t['term'], t['assessments'], t['average_quality'], t['average_performance']) for t in d['data']['terms']]"

echo "== REAL-DATA VERIFICATION: change Q4 Medium -> Hard, analytics must change (cache version invalidates)"
docker compose exec -T app php artisan tinker --execute="\App\Models\Question::find($Q4)->update(['difficulty_level'=>'hard']); echo 'ok';" | tr -dc 'a-z'; echo
api GET "/analytics/overview" | py "'difficulty after change: ' + str([(x['level'], x['count'], x['percentage']) for x in d['data']['difficulty']['distribution']]) + ' status=' + str(d['data']['difficulty']['balance_status'])"
echo "== REAL-DATA VERIFICATION: finalize a 5th grade (10/10) -> performance must update"
S=$(api POST /students '{"student_identifier":"STU005","name":"Student 5","section":"B"}' | py "d['data']['id']")
SUB=$(api POST "/assessments/$ASSESS/submissions" "{\"student_id\":$S,\"submission_identifier\":\"MID-005\"}" | py "d['data']['id']")
A=$(api POST "/submissions/$SUB/answers" "{\"question_id\":$Q1,\"answer_text\":\"Keys.\"}" | py "d['data']['id']")
api PATCH "/submissions/$SUB/status" '{"status":"UNDER_REVIEW"}' >/dev/null; api PUT "/student-answers/$A" '{"awarded_marks":10}' >/dev/null; api PATCH "/submissions/$SUB/status" '{"status":"GRADED"}' >/dev/null
api GET "/analytics/overview" | py "'performance after change: avg=%s submissions=%s responses=%s status=%s' % tuple(d['data']['performance'][k] for k in ('average_percentage','submissions','responses','status'))"

echo "== Compare + export (pdf/csv/json) + audit"
set +o pipefail
FINAL=$(api POST "/courses/$COURSE/assessments" '{"title":"Final Examination","type":"final","total_marks":100,"status":"draft","assessment_date":"2026-12-10"}' | py "d['data']['id']")
api GET "/analytics/compare?assessment_ids[]=$ASSESS&assessment_ids[]=$FINAL" | py "[(a['title'], a['quality_score'], a['performance']['available'], a['performance'].get('average_percentage')) for a in d['data']['assessments']]"
echo -n "pdf  -> "; curl -s -b "$JAR" "$BASE/api/analytics/export?format=pdf&course_id=$COURSE" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" | head -c 8; echo
echo -n "csv  -> "; curl -s -b "$JAR" "$BASE/api/analytics/export?format=csv" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" | grep -E "^difficulty,|^performance,average" | tr '\n' ' '; echo
echo -n "json -> "; code "/analytics/export?format=json"; echo
docker compose exec -T mysql mysql -uroot -proot123 facultylens -e "SELECT action, COUNT(*) c FROM audit_logs WHERE action='ANALYTICS_EXPORTED' AND user_id=(SELECT id FROM users WHERE email='$EMAIL') GROUP BY action;" 2>/dev/null

echo "== Cross-faculty isolation"
JAR2="$(mktemp)"; JAR_SAVE="$JAR"; JAR="$JAR2"
curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" >/dev/null
api POST /auth/register "{\"name\":\"Other\",\"email\":\"other.$EMAIL\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"EEE\",\"designation\":\"Lecturer\"}" >/dev/null
api POST /auth/login "{\"email\":\"other.$EMAIL\",\"password\":\"$PASS\"}" >/dev/null
echo -n "other overview courses/questions -> "; api GET /analytics/overview | py "(d['data']['kpis']['courses']['value'], d['data']['kpis']['questions']['value'], d['data']['kpis']['average_quality']['value'])"
echo -n "other course analytics -> "; code "/analytics/courses/$COURSE"; echo
echo -n "other course_id filter -> "; code "/analytics/overview?course_id=$COURSE"; echo
echo -n "other compare          -> "; code "/analytics/compare?assessment_ids[]=$ASSESS&assessment_ids[]=$FINAL"; echo
JAR="$JAR_SAVE"
echo -n "anonymous overview     -> "; curl -s -o /dev/null -w "%{http_code}\n" "$BASE/api/analytics/overview" -H "Accept: application/json"
echo "== E2E complete"
