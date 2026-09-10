#!/usr/bin/env bash
# STEP 35 E2E: Login -> AI Evaluation -> Create Dataset -> Validate -> Run (queued) -> Metrics -> Confusion Matrix -> Errors -> Compare -> Export
set -euo pipefail
BASE="${BASE:-http://127.0.0.1:8080}"
JAR="$(mktemp)"
EMAIL="aieval.e2e.$(date +%s)@university.edu"
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
jget() { python -c "import sys,json;d=json.load(sys.stdin);print(eval(\"d$1\"))"; }
code() { curl -s -o /dev/null -w "%{http_code}" -b "$JAR" "$BASE/api$1" -H "Accept: application/json" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/"; }

echo "== CSRF + register + login"
curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" >/dev/null
api POST /auth/register "{\"name\":\"E2E Evaluator\",\"email\":\"$EMAIL\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"CSE\",\"designation\":\"Lecturer\"}" | head -c 150; echo
api POST /auth/login "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}" | head -c 120; echo

echo "== Overview before any evaluation (must be NOT_EVALUATED, no fake numbers)"
api GET /ai/evaluation | jget "['data']['overall_status'], d['data']['run_count'], [t['headline_value'] for t in d['data']['tasks']]"

echo "== Model registry sync (Laravel -> FastAPI inventory)"
api GET "/ai/evaluation/models?sync=1" | python -c "import sys,json;d=json.load(sys.stdin);print([(m['model_name'],m['model_type'],m['task']) for m in d['data']['models']][:6])"

echo "== Invalid dataset: missing label + invalid label -> validation fails -> run blocked (422)"
BAD=$(api POST /ai/evaluation/datasets '{"name":"Bad set","task":"DIFFICULTY_CLASSIFICATION","split":"TEST","examples":[{"input_data":{"question":"Define a primary key."},"expected_output":{}},{"input_data":{"question":"Q2"},"expected_output":{"expected_difficulty":"IMPOSSIBLE"}}]}' | jget "['data']['id']")
api POST "/ai/evaluation/datasets/$BAD/validate" | jget "['data']['is_valid'], d['data']['invalid_examples'], d['data']['problems'][0]['errors']"
echo -n "run invalid dataset -> "; curl -s -o /dev/null -w "%{http_code}\n" -b "$JAR" -X POST "$BASE/api/ai/evaluation/datasets/$BAD/run" -H "Accept: application/json" -H "X-XSRF-TOKEN: $(grep XSRF-TOKEN "$JAR" | awk '{print $7}' | python -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))")" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/"

echo "== Faculty-validated difficulty dataset (held-out TEST split)"
DS=$(api POST /ai/evaluation/datasets '{"name":"Difficulty E2E v1","version":"v1","task":"DIFFICULTY_CLASSIFICATION","source":"FACULTY_VALIDATED","split":"TEST","examples":[
 {"input_data":{"question":"Define a primary key."},"expected_output":{"expected_difficulty":"EASY"}},
 {"input_data":{"question":"List the ACID properties of a transaction."},"expected_output":{"expected_difficulty":"EASY"}},
 {"input_data":{"question":"Explain the difference between 2NF and 3NF with an example."},"expected_output":{"expected_difficulty":"MEDIUM"}},
 {"input_data":{"question":"Describe how a B+ tree index speeds up range queries."},"expected_output":{"expected_difficulty":"MEDIUM"}},
 {"input_data":{"question":"Critically evaluate two concurrency control protocols and justify which is preferable for a high-contention OLTP workload, considering deadlock handling and recoverability."},"expected_output":{"expected_difficulty":"HARD"}},
 {"input_data":{"question":"Design a normalized schema for a university and prove it is in BCNF, analysing every functional dependency."},"expected_output":{"expected_difficulty":"HARD"}},
 {"input_data":{"question":"Define a primary key."},"expected_output":{"expected_difficulty":"EASY"}}
]}')
DSID=$(echo "$DS" | jget "['data']['id']")
echo "$DS" | jget "['data']['status'], d['data']['import']"
api POST "/ai/evaluation/datasets/$DSID/validate" | jget "['data']['is_valid'], d['data']['total_examples'], d['data']['label_distribution'], d['data']['size_category'], d['data']['small_dataset_warning']"

echo "== Start evaluation (202, queued) and wait for the queue worker"
RUN=$(api POST "/ai/evaluation/datasets/$DSID/run" '{"note":"e2e"}')
echo "$RUN" | jget "['data']['id'], d['data']['status']"
RID=$(echo "$RUN" | jget "['data']['id']")
for i in $(seq 1 40); do
  ST=$(api GET "/ai/evaluation/runs/$RID" | jget "['data']['status']")
  [[ "$ST" == "COMPLETED" || "$ST" == "FAILED" ]] && break
  sleep 2
done
echo "run status=$ST"
[[ "$ST" == "COMPLETED" ]] || { echo "run did not complete"; api GET "/ai/evaluation/runs/$RID" | head -c 400; exit 1; }

echo "== Metrics (computed from real predictions vs ground truth)"
api GET "/ai/evaluation/runs/$RID/metrics" | python -c "
import sys,json;d=json.load(sys.stdin)['data'];s=d['scalars']
print('headline:',d['headline_metric'],'accuracy=',s.get('accuracy'),'macro_f1=',s.get('macro_f1'),'weighted_f1=',s.get('weighted_f1'),'support=',s.get('support'))
cm=d['structured']['confusion_matrix'];print('confusion labels:',cm['labels']);[print('  ',l,r) for l,r in zip(cm['labels'],cm['matrix'])]
print('per-class:',[(p['label'],p['precision'],p['recall'],p['f1'],p['support']) for p in d['structured']['per_class']])
print('gates:',[(g['metric'],g['value'],g['min'],g['passed']) for g in d['gates']])"
api GET "/ai/evaluation/runs/$RID" | jget "['data']['gate_status'], d['data']['summary']['size_category'], d['data']['summary']['warnings'], d['data']['model']"

echo "== Example-level errors"
api GET "/ai/evaluation/runs/$RID/errors" | python -c "import sys,json;d=json.load(sys.stdin);print('errors:',d['meta']['total'],d['meta']['error_breakdown']);[print('  #',e['example_id'],e['expected_output'],'->',e['prediction'].get('label'),e['error_type']) for e in d['data'][:5]]"

echo "== Second run on the same dataset -> regression detection + comparison"
RUN2=$(api POST "/ai/evaluation/datasets/$DSID/run" | jget "['data']['id']")
for i in $(seq 1 40); do ST=$(api GET "/ai/evaluation/runs/$RUN2" | jget "['data']['status']"); [[ "$ST" == "COMPLETED" || "$ST" == "FAILED" ]] && break; sleep 2; done
api GET "/ai/evaluation/runs/$RUN2" | jget "['data']['status'], d['data']['summary']['regression']"
api GET "/ai/evaluation/compare?run_a=$RID&run_b=$RUN2" | python -c "import sys,json;d=json.load(sys.stdin)['data'];print('same_task',d['same_task'],[(r['metric'],r['run_a'],r['run_b'],r['direction']) for r in d['rows'] if r['metric'] in ('accuracy','macro_f1')]);print(d['note'])"

echo "== History + overview now evaluated"
api GET "/ai/evaluation/runs?task=DIFFICULTY_CLASSIFICATION" | jget "['meta']['total']"
api GET /ai/evaluation | python -c "import sys,json;d=json.load(sys.stdin)['data'];print(d['overall_status'],[(t['task'],t['headline_value'],t['gate_status']) for t in d['tasks'] if t['evaluated']])"

echo "== Exports (json/csv/pdf) + audit"
echo -n "json -> "; code "/ai/evaluation/runs/$RID/export?format=json"; echo
echo -n "csv  -> "; curl -s -b "$JAR" "$BASE/api/ai/evaluation/runs/$RID/export?format=csv" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" | head -3
echo -n "pdf  -> "; curl -s -b "$JAR" "$BASE/api/ai/evaluation/runs/$RID/export?format=pdf" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" | head -c 8; echo
docker compose exec -T mysql mysql -uroot -proot123 facultylens -e "SELECT action, COUNT(*) c FROM audit_logs WHERE action LIKE 'AI_EVALUATION%' OR action='AI_MODEL_REGISTERED' GROUP BY action;" 2>/dev/null
docker compose exec -T mysql mysql -uroot -proot123 facultylens -e "SELECT COUNT(*) predictions, SUM(is_correct) correct FROM ai_evaluation_predictions WHERE evaluation_run_id=$RID; SELECT metric_name, metric_value FROM ai_evaluation_results WHERE evaluation_run_id=$RID AND metric_name IN ('accuracy','macro_f1');" 2>/dev/null

echo "== Cross-faculty isolation"
JAR2="$(mktemp)"; JAR_SAVE="$JAR"; JAR="$JAR2"
curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" >/dev/null
api POST /auth/register "{\"name\":\"Other\",\"email\":\"other.$EMAIL\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"EEE\",\"designation\":\"Lecturer\"}" >/dev/null
api POST /auth/login "{\"email\":\"other.$EMAIL\",\"password\":\"$PASS\"}" >/dev/null
echo -n "other GET dataset -> "; code "/ai/evaluation/datasets/$DSID"; echo
echo -n "other GET run     -> "; code "/ai/evaluation/runs/$RID"; echo
echo -n "other export      -> "; code "/ai/evaluation/runs/$RID/export?format=json"; echo
echo -n "other overview run_count -> "; api GET /ai/evaluation | jget "['data']['run_count']"
JAR="$JAR_SAVE"
echo -n "anonymous overview -> "; curl -s -o /dev/null -w "%{http_code}\n" "$BASE/api/ai/evaluation" -H "Accept: application/json"
echo "== E2E complete"
