#!/usr/bin/env bash
# STEP 43 — run one k6 scenario against the performance stack while sampling `docker stats` every 5 s.
#   bash performance/run.sh <scenario.js> [k6 args...]     e.g. bash performance/run.sh auth.js -e PROFILE=ramp
# Environment: PROFILE, RUN_TAG, BASE_URL … are passed through to k6 (see performance/load/lib/profiles.js).
# Output: performance/results/<scenario>-<profile>[-tag].json (k6 summary) and .stats.csv (container resources).
set -uo pipefail
cd "$(dirname "$0")/.."
SCRIPT="$1"; shift
K6="${K6:-/c/Program Files/k6/k6.exe}"; [[ -x "$K6" ]] || K6=k6
NAME="$(basename "$SCRIPT" .js)-${PROFILE:-baseline}${RUN_TAG:+-$RUN_TAG}"
STATS="performance/results/${NAME}.stats.csv"
mkdir -p performance/results
echo "ts,container,cpu_pct,mem_used,mem_pct,net_io,block_io" > "$STATS"
( while true; do
    docker stats --no-stream --format "{{.Name}},{{.CPUPerc}},{{.MemUsage}},{{.MemPerc}},{{.NetIO}},{{.BlockIO}}" 2>/dev/null \
      | grep facultylens-perf | sed "s/^/$(date +%H:%M:%S),/" >> "$STATS"
    sleep 5
  done ) &
SAMPLER=$!
trap 'kill $SAMPLER 2>/dev/null' EXIT

MYSQL_BEFORE=$(docker exec facultylens-perf-mysql mysql -uroot -pperf_root_pass -N -e "SHOW GLOBAL STATUS LIKE 'Max_used_connections'" 2>/dev/null | awk '{print $2}')
START=$(date +%s)
"$K6" run --quiet ${PROFILE:+-e PROFILE=$PROFILE} ${RUN_TAG:+-e RUN_TAG=$RUN_TAG} "$@" "performance/load/$SCRIPT"
RC=$?
END=$(date +%s)
kill $SAMPLER 2>/dev/null

echo "── resources during ${NAME} ($((END-START))s) ──"
python - "$STATS" <<'EOF'
import csv,sys,collections
rows=list(csv.DictReader(open(sys.argv[1])))
by=collections.defaultdict(list)
for r in rows:
    try: by[r['container']].append((float(r['cpu_pct'].rstrip('%')), r['mem_used'].split('/')[0].strip(), float(r['mem_pct'].rstrip('%'))))
    except Exception: pass
for c,v in sorted(by.items()):
    cpu=[x[0] for x in v]; mem=[x[2] for x in v]
    print(f"  {c:32s} cpu avg {sum(cpu)/len(cpu):6.1f}%  max {max(cpu):6.1f}%   mem max {max(mem):5.1f}% ({v[mem.index(max(mem))][1]})  samples {len(v)}")
EOF
docker exec facultylens-perf-mysql mysql -uroot -pperf_root_pass -N -e "SHOW GLOBAL STATUS LIKE 'Threads_connected'; SHOW GLOBAL STATUS LIKE 'Max_used_connections'; SHOW VARIABLES LIKE 'max_connections'" 2>/dev/null | tr '\n' ' ' | sed 's/^/  mysql: /'; echo " (max_used before run: ${MYSQL_BEFORE:-?})"
docker exec facultylens-perf-redis redis-cli -a perf_redis_pass --no-auth-warning INFO memory 2>/dev/null | grep -E "^used_memory_human" | sed 's/^/  redis: /'
docker exec facultylens-perf-redis redis-cli -a perf_redis_pass --no-auth-warning LLEN queues:default 2>/dev/null | sed 's/^/  redis queue depth: /'
exit $RC
