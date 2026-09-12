#!/usr/bin/env bash
# STEP 43 §40 — academic-correctness snapshot. Captures the analytic/AI outputs that optimizations must not change,
# as normalized JSON (volatile fields removed) so before/after files can be diffed byte-for-byte.
#   bash performance/snapshot-academic.sh before|after
set -uo pipefail
cd "$(dirname "$0")/.."
TAG="${1:-before}"; OUT="performance/results/academic-${TAG}"; mkdir -p "$OUT"
BASE="${BASE_URL:-http://127.0.0.1:8090}"; AI="${AI_BASE_URL:-http://127.0.0.1:8011}"; JAR=$(mktemp)
H=(-H "Accept: application/json" -H "Origin: $BASE" -H "Referer: $BASE/")
curl -s -c "$JAR" -b "$JAR" "$BASE/sanctum/csrf-cookie" "${H[@]}" -o /dev/null
X=$(grep XSRF-TOKEN "$JAR" | awk '{print $7}' | python -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))")
curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/api/auth/login" "${H[@]}" -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $X" -d '{"email":"perf.faculty@example.com","password":"PerfTest#2026"}' -o /dev/null
get() { curl -s -b "$JAR" "$BASE/api$1" "${H[@]}"; }
norm() { python -c "
import sys,json
VOL={'generated_at','served_at','cached','cache_ttl_seconds','data_version','updated_at','created_at','analyzed_at','compared_at','timestamp','request_id','processing_time_ms','duration_ms','expires_at','id','analysis_id','report_id','analysis_report_id'}
def clean(o):
    if isinstance(o,dict): return {k:clean(v) for k,v in sorted(o.items()) if k not in VOL}
    if isinstance(o,list): return [clean(x) for x in o]
    return o
print(json.dumps(clean(json.load(sys.stdin)),indent=1,sort_keys=True))"; }

AS=$(get "/assessments?per_page=100")
for n in 10 50 100 200; do
  ID=$(echo "$AS" | python -c "import sys,json;d=json.load(sys.stdin);print(next(a['id'] for a in d['data'] if '($n questions)' in a['title']))")
  CID=$(echo "$AS" | python -c "import sys,json;d=json.load(sys.stdin);print(next(a['course_id'] for a in d['data'] if a['id']==$ID))")
  get "/ai/assessments/$ID/analysis" | norm > "$OUT/analysis-${n}q.json"
  get "/assessments/$ID/performance" | norm > "$OUT/performance-${n}q.json"
  get "/assessments/$ID/analysis-history" | norm > "$OUT/analysis-history-${n}q.json"
  get "/courses/$CID/co-po-mapping" | norm > "$OUT/copo-course-${n}q.json"
done
get "/analytics/overview?fresh=1" | norm > "$OUT/analytics-overview.json"
CID=$(echo "$AS" | python -c "import sys,json;d=json.load(sys.stdin);print(d['data'][0]['course_id'])")
for s in assessments performance outcomes ai similarity; do get "/analytics/courses/$CID/$s" | norm > "$OUT/analytics-course-$s.json"; done
# AI engine determinism: identical payload → identical scores/labels (no persistence involved)
python - "$AI" "$OUT" <<'EOF'
import json,sys,urllib.request
ai,out=sys.argv[1],sys.argv[2]
qs=[{"id":i+1,"number":i+1,"text":f"Explain a B+ tree index for topic {i%5} and discuss trade-offs under {10+i} concurrent transactions.","marks":5,"question_type":"descriptive","difficulty":["easy","medium","hard"][i%3],"cognitive_level":["Remember","Understand","Apply","Analyze","Evaluate","Create"][i%6],"topics":[],"learning_outcome_code":f"LO{1+i%3}"} for i in range(20)]
los=[{"id":i+1,"code":f"LO{i+1}","description":d} for i,d in enumerate(["Explain relational database indexing structures.","Apply normalization to relational schemas.","Evaluate transaction isolation and concurrency control."])]
prev=[{"id":100+i,"number":i+1,"text":f"Describe a B+ tree index for topic {i%5} and its trade-offs (old exam {i}).","assessment_title":"Old","term":"Spring","year":"2024"} for i in range(50)]
body=json.dumps({"course_id":1,"course_name":"P","assessment":{"id":1,"title":"P","total_marks":100,"course_code":"P","course_title":"P"},"questions":qs,"learning_outcomes":los,"course_topics":["indexing","normalization","transactions"],"previous_questions":prev}).encode()
req=urllib.request.Request(f"{ai}/api/v1/analyze-assessment",data=body,headers={"Content-Type":"application/json","X-AI-Service-Key":"perf-ai-key-not-secret"})
d=json.load(urllib.request.urlopen(req,timeout=600))
def clean(o):
    if isinstance(o,dict): return {k:clean(v) for k,v in sorted(o.items()) if k not in ('processing_time_ms','timestamp','generated_at','duration_ms','id')}  # recommendation ids are random per run
    if isinstance(o,list): return [clean(x) for x in o]
    return o
json.dump(clean(d),open(f"{out}/ai-analyze-assessment-20q.json","w"),indent=1,sort_keys=True)
print("quality", d.get("quality_analysis",{}).get("overall_quality_score"), d.get("quality_analysis",{}).get("rating"), "| recs", d.get("recommendations",{}).get("total_recommendations"), "| dup", d.get("similarity_analysis",{}).get("potential_duplicates_count"))
EOF
ls "$OUT" | wc -l; echo "snapshot → $OUT"
