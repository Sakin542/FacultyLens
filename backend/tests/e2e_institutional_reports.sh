#!/usr/bin/env bash
# STEP 39 E2E: Login -> CSE101 + midterm (10 questions) -> real AI analysis -> version v1.0 -> Reports:
#              types -> filters -> Assessment Quality @ ASSESSMENT_VERSION -> preview -> verify summary
#              -> generate PDF -> wait -> download -> verify file
#              -> generate CSV -> open -> verify headers/data
#              -> generate XLSX -> open workbook -> verify sheets/data
#              -> real-data verification (Q4 MEDIUM -> HARD; old report unchanged)
#              -> version isolation (v2 with 50 marks; v1 report still 100)
#              -> privacy (student performance export has no identities)
#              -> authorization (other faculty cannot preview/download) -> history -> delete -> audit
set -euo pipefail
BASE="${BASE:-http://127.0.0.1:8080}"
JAR="$(mktemp)"
JAR2="$(mktemp)"
EMAIL="reports.e2e.$(date +%s)@university.edu"
EMAIL2="reports.other.$(date +%s)@university.edu"
PASS="Password123!"
TMPD=$(cygpath -m "$(mktemp -d)" 2>/dev/null || mktemp -d)

api() { # method path [json] [jar]
  local m="$1" p="$2" d="${3:-}" jar="${4:-$JAR}"
  local xsrf; xsrf=$(grep XSRF-TOKEN "$jar" | awk '{print $7}' | python -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))")
  if [[ -n "$d" ]]; then
    curl -s -b "$jar" -c "$jar" -X "$m" "$BASE/api$p" -H "Accept: application/json" -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $xsrf" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" -d "$d"
  else
    curl -s -b "$jar" -c "$jar" -X "$m" "$BASE/api$p" -H "Accept: application/json" -H "X-XSRF-TOKEN: $xsrf" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/"
  fi
}
py() { python -c "import sys,json;d=json.load(sys.stdin);print($1)"; }
did() { python -c "import sys,json;d=json.load(sys.stdin)
if 'data' not in d or not isinstance(d['data'],dict): sys.stderr.write('API error: '+json.dumps(d)[:300]+'\n'); sys.exit(1)
print(d['data']['id'])"; }
code() { # path [jar]
  curl -s -o /dev/null -w "%{http_code}" -b "${2:-$JAR}" "$BASE/api$1" -H "Accept: application/json" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/"; }
download() { # report_id out_file [jar]
  curl -s -b "${3:-$JAR}" -o "$2" -w "%{http_code} %{content_type}" "$BASE/api/reports/$1/download" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/"; }
wait_done() { # report_id -> waits for COMPLETED/FAILED (async reports)
  for _ in $(seq 1 40); do
    local st; st=$(api GET "/reports/$1" | py "d['data']['status']")
    if [[ "$st" == "COMPLETED" || "$st" == "FAILED" ]]; then echo "$st"; return; fi
    sleep 1
  done
  echo "TIMEOUT"
}
login() { # email jar
  curl -s -c "$2" "$BASE/sanctum/csrf-cookie" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" >/dev/null
  api POST /auth/register "{\"name\":\"E2E Reports\",\"email\":\"$1\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"CSE\",\"designation\":\"Lecturer\"}" "$2" >/dev/null
  api POST /auth/login "{\"email\":\"$1\",\"password\":\"$PASS\"}" "$2" | head -c 60; echo
}

echo "== Login (faculty A) + second faculty (B)"
login "$EMAIL" "$JAR"
login "$EMAIL2" "$JAR2"

echo "== Report types offered to ordinary faculty (no INSTITUTIONAL_SUMMARY, no INSTITUTION scope)"
api GET /reports/types | py "([t['key'] for t in d['data']['types']], d['data']['scopes'], d['data']['formats'])"
echo -n "institution scope for faculty -> HTTP "; api POST /reports/preview '{"report_type":"ASSESSMENT_QUALITY","scope_type":"INSTITUTION","filters":{}}' | py "d['message']"

echo "== CSE101: 3 COs, midterm with 10 questions"
COURSE=$(api POST /courses '{"course_code":"CSE101","course_name":"Database Systems","semester":"Fall","academic_year":"2026","credits":3,"status":"active"}' | py "d['data']['id']")
LO1=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"CO1","description":"Explain relational database concepts.","cognitive_level":"Understand","sort_order":1}' | py "d['data']['id']")
LO2=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"CO2","description":"Apply normalization to design schemas.","cognitive_level":"Apply","sort_order":2}' | py "d['data']['id']")
LO3=$(api POST "/courses/$COURSE/learning-outcomes" '{"code":"CO3","description":"Optimize SQL queries.","cognitive_level":"Analyze","sort_order":3}' | py "d['data']['id']")
ASSESS=$(api POST "/courses/$COURSE/assessments" '{"title":"Midterm Examination","type":"midterm","total_marks":100,"status":"draft","assessment_date":"2026-10-01"}' | py "d['data']['id']")
QIDS=$(docker compose exec -T app php artisan tinker --execute="
\$a=$ASSESS; \$ids=[];
\$spec=[['Define a primary key and a foreign key.','easy','Remember',$LO1],['List the ACID properties.','easy','Remember',$LO1],['State the purpose of normalization.','easy','Understand',$LO2],
['Explain the difference between 2NF and 3NF.','medium','Understand',$LO2],['Describe how a B+ tree index speeds up range queries.','medium','Understand',$LO3],['Decompose a relation into 3NF.','medium','Apply',$LO2],
['Write an SQL query with a join and GROUP BY.','medium','Apply',$LO1],['Apply indexing to improve a slow query.','medium','Apply',$LO3],
['Evaluate two concurrency control protocols.','hard','Evaluate',$LO3],['Design a normalized university schema in BCNF.','hard','Create',$LO2]];
foreach(\$spec as \$i=>\$s){ \$ids[]=\App\Models\Question::create(['assessment_id'=>\$a,'question_number'=>\$i+1,'question_text'=>\$s[0],'question_type'=>'descriptive','marks'=>10,'difficulty_level'=>\$s[1],'cognitive_level'=>\$s[2],'learning_outcome_id'=>\$s[3]])->id; }
echo implode(',', \$ids);" | tr -dc '0-9,')
IFS=, read -r Q1 Q2 Q3 Q4 Q5 Q6 Q7 Q8 Q9 Q10 <<< "$QIDS"
echo "course=$COURSE assessment=$ASSESS questions=$QIDS"

echo "== Real AI analysis (STEP 13) so quality data exists"
api POST /ai/analyze-assessment "{\"assessment_id\":$ASSESS}" | py "(d['status'], d['data'].get('report',{}).get('overall_score') if isinstance(d.get('data'),dict) else None)"

echo "== Version v1.0 (STEP 38 snapshot of the 10 questions / 100 marks)"
V1=$(api POST "/assessments/$ASSESS/versions" '{"change_summary":"Initial paper"}' | py "d['data']['version']['id']")
api GET "/assessments/$ASSESS/versions/$V1" | py "(d['data']['version']['version_label'], d['data']['version']['total_marks'], d['data']['version']['question_count'])"

echo "== Filters for the builder are scoped to my courses"
api GET "/reports/filters?report_type=ASSESSMENT_QUALITY&scope_type=ASSESSMENT_VERSION" | py "(d['data']['applicable'], [c['code'] for c in d['data']['options']['courses']], [v['version_label'] for v in d['data']['options']['assessment_versions']])"

echo "== Preview: Assessment Quality @ ASSESSMENT_VERSION (CSE101 / Midterm / v1.0)"
FILTERS="{\"course_id\":$COURSE,\"assessment_id\":$ASSESS,\"assessment_version_id\":$V1}"
PREVIEW=$(api POST /reports/preview "{\"report_type\":\"ASSESSMENT_QUALITY\",\"scope_type\":\"ASSESSMENT_VERSION\",\"filters\":$FILTERS}")
echo "$PREVIEW" | py "(d['data']['scope_description'], d['data']['record_count'], d['data']['has_data'], d['data']['will_queue'], d['data']['metadata']['assessment_version']['version_label'], d['data']['metadata']['generated_by']['name'])"
echo "$PREVIEW" | py "'summary: ' + str({s['label']: s['value'] for s in d['data']['summary']})"
echo "$PREVIEW" | py "'tables: ' + str([(t['key'], t['total_rows']) for t in d['data']['tables']])"
echo "$PREVIEW" | py "'warnings: ' + str(d['data']['warnings'])"

echo "== Generate PDF -> wait -> download -> verify"
PDF=$(api POST /reports "{\"report_type\":\"ASSESSMENT_QUALITY\",\"scope_type\":\"ASSESSMENT_VERSION\",\"filters\":$FILTERS,\"format\":\"PDF\"}")
PDF_ID=$(echo "$PDF" | py "d['data']['id']")
echo "$PDF" | py "(d['status'], d['message'], d['data']['status'], d['data']['format'], d['data']['is_async'], d['data']['record_count'], d['data']['expires_at'] is not None, 'file_path' in d['data'])"
echo "status after wait: $(wait_done "$PDF_ID")"
echo -n "download -> "; download "$PDF_ID" "$TMPD/report.pdf"; echo
head -c 5 "$TMPD/report.pdf"; echo "  ($(wc -c < "$TMPD/report.pdf") bytes)"

echo "== Generate CSV -> open -> verify headers/data"
CSV_ID=$(api POST /reports "{\"report_type\":\"ASSESSMENT_QUALITY\",\"scope_type\":\"ASSESSMENT_VERSION\",\"filters\":$FILTERS,\"format\":\"CSV\"}" | py "d['data']['id']")
echo "status: $(wait_done "$CSV_ID")"
echo -n "download -> "; download "$CSV_ID" "$TMPD/report.csv"; echo
grep -n '^"\?# Assessment Quality' "$TMPD/report.csv"
grep -n "^course_code,assessment,assessment_type,version,overall_quality,rating" "$TMPD/report.csv"
grep -n '^CSE101,"Midterm Examination",midterm,v1.0,' "$TMPD/report.csv"
grep -c "FacultyLens" "$TMPD/report.csv" | sed 's/^/FacultyLens footer\/header lines: /'

echo "== Generate XLSX -> open workbook -> verify sheets/data"
XLSX_ID=$(api POST /reports "{\"report_type\":\"ASSESSMENT\",\"scope_type\":\"ASSESSMENT_VERSION\",\"filters\":$FILTERS,\"format\":\"XLSX\"}" | py "d['data']['id']")
echo "status: $(wait_done "$XLSX_ID")"
echo -n "download -> "; download "$XLSX_ID" "$TMPD/report.xlsx"; echo
python - "$TMPD/report.xlsx" <<'EOF'
import sys, zipfile, re
z = zipfile.ZipFile(sys.argv[1])
wb = z.read('xl/workbook.xml').decode()
sheets = re.findall(r'<sheet name="([^"]+)"', wb)
print('sheets:', sheets)
q = [n for n in z.namelist() if n.startswith('xl/worksheets/sheet')]
qs = z.read('xl/worksheets/sheet%d.xml' % (sheets.index('Question Summary') + 1)).decode()
print('question sheet has Q1 text:', 'Define a primary key' in qs, '| numeric marks cells:', qs.count('<v>10</v>') >= 10)
s1 = z.read('xl/worksheets/sheet1.xml').decode()
print('summary sheet mentions v1.0:', 'v1.0' in s1, '| product:', 'FacultyLens' in s1)
EOF

echo "== Real-data verification: Q4 MEDIUM -> HARD, new report reflects it, old file unchanged"
OLD_ID=$(api POST /reports "{\"report_type\":\"QUESTION_ANALYSIS\",\"scope_type\":\"ASSESSMENT\",\"filters\":{\"course_id\":$COURSE,\"assessment_id\":$ASSESS},\"format\":\"CSV\"}" | py "d['data']['id']")
wait_done "$OLD_ID" >/dev/null; download "$OLD_ID" "$TMPD/q_old.csv" >/dev/null
api PUT "/questions/$Q4" '{"difficulty_level":"hard"}' >/dev/null 2>&1 || true
docker compose exec -T app php artisan tinker --execute="\App\Models\Question::where('id',$Q4)->update(['difficulty_level'=>'hard']); echo 'Q4 difficulty -> ' . \App\Models\Question::find($Q4)->difficulty_level;"; echo
NEW_ID=$(api POST /reports "{\"report_type\":\"QUESTION_ANALYSIS\",\"scope_type\":\"ASSESSMENT\",\"filters\":{\"course_id\":$COURSE,\"assessment_id\":$ASSESS},\"format\":\"CSV\"}" | py "d['data']['id']")
wait_done "$NEW_ID" >/dev/null; download "$NEW_ID" "$TMPD/q_new.csv" >/dev/null
echo -n "old report Q4: "; grep -E "^CSE101,\"?Midterm[^,]*\"?,,4," "$TMPD/q_old.csv" | cut -d, -f8 | head -1
echo -n "new report Q4: "; grep -E "^CSE101,\"?Midterm[^,]*\"?,,4," "$TMPD/q_new.csv" | cut -d, -f8 | head -1
download "$OLD_ID" "$TMPD/q_old2.csv" >/dev/null; cmp -s "$TMPD/q_old.csv" "$TMPD/q_old2.csv" && echo "old report file unchanged: True"

echo "== Version isolation: v2 (marks halved -> 50) while the v1 report still says 100"
V2=$(api POST "/assessments/$ASSESS/versions" '{"change_summary":"Halved marks"}' | py "d['data']['version']['id']")
V2ROWS=$(api GET "/assessments/$ASSESS/versions/$V2" | python -c "import sys,json;d=json.load(sys.stdin);qs=[{k:q.get(k) for k in ['original_question_id','question_number','section_name','question_text','question_type','marks','difficulty_level','cognitive_level','topic','learning_outcome_id','program_outcome_id','expected_answer']} for q in d['data']['version']['questions']];[q.update({'marks':5}) for q in qs];print(json.dumps({'questions':qs}))")
api PUT "/assessment-versions/$V2" "$V2ROWS" | py "(d['data']['version']['version_label'], d['data']['version']['total_marks'])"
V1R=$(api POST /reports "{\"report_type\":\"ASSESSMENT\",\"scope_type\":\"ASSESSMENT_VERSION\",\"filters\":$FILTERS,\"format\":\"CSV\"}" | py "d['data']['id']")
wait_done "$V1R" >/dev/null; download "$V1R" "$TMPD/v1.csv" >/dev/null
echo -n "v1 report after v2 exists -> "; grep -E '^"Total Marks"' "$TMPD/v1.csv"
V2R=$(api POST /reports "{\"report_type\":\"ASSESSMENT\",\"scope_type\":\"ASSESSMENT_VERSION\",\"filters\":{\"course_id\":$COURSE,\"assessment_id\":$ASSESS,\"assessment_version_id\":$V2},\"format\":\"CSV\"}" | py "d['data']['id']")
wait_done "$V2R" >/dev/null; download "$V2R" "$TMPD/v2.csv" >/dev/null
echo -n "v2 report -> "; grep -E '^"Total Marks"' "$TMPD/v2.csv"
api GET "/reports/$V1R" | py "('bound to version', d['data']['assessment_version']['version_label'])"

echo "== Privacy: finalized grades -> student performance export must contain no identities"
MARKS=(8 7 6 9)
for i in 1 2 3 4; do
  M=${MARKS[$((i-1))]}
  S=$(api POST /students "{\"student_identifier\":\"SECRET-ID-$i\",\"name\":\"Secret Student $i\",\"section\":\"A\"}" | did)
  SUB=$(api POST "/assessments/$ASSESS/submissions" "{\"student_id\":$S,\"submission_identifier\":\"MID-00$i\"}" | did)
  A=$(api POST "/submissions/$SUB/answers" "{\"question_id\":$Q1,\"answer_text\":\"PRIVATE ANSWER TEXT $i\"}" | did)
  api PATCH "/submissions/$SUB/status" '{"status":"UNDER_REVIEW"}' >/dev/null
  api PUT "/student-answers/$A" "{\"awarded_marks\":$M,\"faculty_feedback\":\"ok\"}" >/dev/null
  api PATCH "/submissions/$SUB/status" '{"status":"GRADED"}' | py "(d.get('status'), d.get('data',{}).get('status') if isinstance(d.get('data'),dict) else d.get('message'), d.get('data',{}).get('grading_status') if isinstance(d.get('data'),dict) else None)"
done
SP=$(api POST /reports "{\"report_type\":\"STUDENT_PERFORMANCE\",\"scope_type\":\"COURSE\",\"filters\":{\"course_id\":$COURSE},\"format\":\"CSV\"}")
SP_ID=$(echo "$SP" | did); echo "$SP" | py "('contains_student_data', d['data']['contains_student_data'])"
wait_done "$SP_ID" >/dev/null; download "$SP_ID" "$TMPD/perf.csv" >/dev/null
grep -E '^"(Average %|Student Count|Response Count)"' "$TMPD/perf.csv"
echo -n "identity leak check (expect 0): "; grep -c "Secret Student\|SECRET-ID\|PRIVATE ANSWER" "$TMPD/perf.csv" || true

echo "== Authorization: faculty B cannot preview, view or download A's reports; B's own list is empty"
api POST /reports/preview "{\"report_type\":\"ASSESSMENT_QUALITY\",\"scope_type\":\"COURSE\",\"filters\":{\"course_id\":$COURSE}}" "$JAR2" | py "d['message']"
echo -n "B GET A's report -> HTTP "; code "/reports/$PDF_ID" "$JAR2"; echo
echo -n "B download A's report -> "; download "$PDF_ID" "$TMPD/b.pdf" "$JAR2" | cut -d' ' -f1; echo
api GET /reports "" "$JAR2" | py "('B total reports', d['data']['pagination']['total'])"

echo "== History (My Reports) + delete + audit"
api GET /reports | py "('A total', d['data']['pagination']['total'], [(r['report_label'], r['format'], r['status'], r['downloadable']) for r in d['data']['items'][:3]])"
api DELETE "/reports/$CSV_ID" | py "d['message']"
echo -n "deleted report -> HTTP "; code "/reports/$CSV_ID"; echo
docker compose exec -T app php artisan tinker --execute="echo json_encode(\App\Models\AuditLog::whereIn('action',['REPORT_PREVIEWED','REPORT_REQUESTED','REPORT_GENERATION_STARTED','REPORT_GENERATION_COMPLETED','REPORT_DOWNLOADED','REPORT_DELETED'])->where('user_id', \App\Models\User::where('email','$EMAIL')->value('id'))->selectRaw('action, COUNT(*) c')->groupBy('action')->pluck('c','action'));"
echo
echo "== DONE (STEP 39 E2E) — files in $TMPD"
