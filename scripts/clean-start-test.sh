#!/usr/bin/env bash
# STEP 41 — "clean machine" start test.
#
# Builds the images and starts a SECOND, isolated copy of the stack (compose project facultylens-clean, fresh
# volumes, different ports) and walks the README quick-start: migrate from zero -> seed -> health/readiness
# -> register/login through the API -> queue worker alive -> integrity check. Tears everything down afterwards
# (including its volumes). The developer's normal `docker compose` stack keeps running untouched.
#
# Usage: bash scripts/clean-start-test.sh [--no-cache] [--keep]
set -euo pipefail
cd "$(dirname "$0")/.."
PROJECT=facultylens-clean
FILES=(-f docker-compose.yml -f scripts/docker-compose.clean-test.yml)
NO_CACHE=""; KEEP=0
for a in "$@"; do case "$a" in --no-cache) NO_CACHE="--no-cache";; --keep) KEEP=1;; esac; done
export CLEAN_APP_KEY="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
BASE=http://127.0.0.1:8090
JAR="$(mktemp)"
TMP_BODY="$(mktemp)"
FAILS=0
step() { echo; echo "== $*"; }
expect() { if [[ "$2" == "$3" ]]; then echo "  ✓ $1: $2"; else echo "  ✗ $1: expected [$3] got [$2]"; FAILS=$((FAILS+1)); fi; }
dc() { docker compose -p "$PROJECT" "${FILES[@]}" "$@"; }
sqlq() { dc exec -T mysql mysql -uroot -proot123 facultylens -N -s -e "$1" 2>/dev/null | tr -d '\r'; }
api() { # method path [json] -> http code; body in $TMP_BODY (re-reads the XSRF cookie: Laravel rotates the session on register/login)
  local xsrf; xsrf=$(grep XSRF-TOKEN "$JAR" | awk '{print $7}' | python -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))")
  curl -s -b "$JAR" -c "$JAR" -X "$1" "$BASE/api$2" -H "Accept: application/json" -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $xsrf" -H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" ${3:+-d "$3"} -o "$TMP_BODY" -w '%{http_code}'; }
cleanup() {
  if [[ $KEEP -eq 1 ]]; then echo; echo "(--keep) stack left running: $BASE"; return; fi
  step "Tear down (containers + volumes of project $PROJECT only)"
  dc down -v --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT
T0=$(date +%s)

step "Prerequisites"
docker version --format '  docker {{.Server.Version}}' && docker compose version | sed 's/^/  /'
[[ -f backend/.env ]] || { echo "  backend/.env missing — run: cp backend/.env.example backend/.env"; exit 1; }

step "Build images ${NO_CACHE:+(no cache) }and start the isolated stack"
dc down -v --remove-orphans >/dev/null 2>&1 || true
dc build $NO_CACHE 2>&1 | tail -3
dc up -d 2>&1 | tail -6
T_UP=$(date +%s)

step "Wait for the database to accept connections and run migrations from an empty schema"
for _ in $(seq 1 60); do dc exec -T mysql mysqladmin ping -h localhost -uroot -proot123 >/dev/null 2>&1 && break; sleep 2; done
dc exec -T app php artisan migrate --force 2>&1 | tail -2
MIGRATED_AT=$(date -u +%Y-%m-%dT%H:%M:%SZ)
TABLES=$(sqlq "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='facultylens'")
echo "  tables created: $TABLES"
[[ "$TABLES" -ge 60 ]] && echo "  ✓ schema created from zero" || { echo "  ✗ only $TABLES tables"; FAILS=$((FAILS+1)); }
expect "migrations pending" "$(dc exec -T app php artisan migrate:status 2>/dev/null | grep -c Pending || true)" "0"

step "Seed the synthetic development dataset"
dc exec -T app php artisan db:seed --force 2>&1 | tail -3
expect "seeded faculty user" "$(sqlq "SELECT COUNT(*) FROM users WHERE email='faculty@example.com'")" "1"
echo "  seeded courses=$(sqlq 'SELECT COUNT(*) FROM courses') questions=$(sqlq 'SELECT COUNT(*) FROM questions') submissions=$(sqlq 'SELECT COUNT(*) FROM student_submissions')"

step "Liveness / readiness (AI model download + load happens on first start)"
for _ in $(seq 1 90); do curl -sf "$BASE/api/health" >/dev/null 2>&1 && break; sleep 2; done
expect "GET /api/health" "$(curl -s "$BASE/api/health" | python -c "import sys,json;print(json.load(sys.stdin)['status'])")" "ok"
READY=""
for _ in $(seq 1 150); do READY=$(curl -s "$BASE/api/health/ready" | python -c "import sys,json;print(json.load(sys.stdin)['status'])" 2>/dev/null || true); [[ "$READY" == "ready" ]] && break; sleep 4; done
expect "GET /api/health/ready" "$READY" "ready"
curl -s "$BASE/api/health/ready" | python -c "import sys,json;print('  components:', json.load(sys.stdin)['components'])"
expect "AI /health" "$(curl -s http://127.0.0.1:8011/health | python -c "import sys,json;print(json.load(sys.stdin)['status'])")" "ok"
expect "AI /ready model_loaded" "$(curl -s http://127.0.0.1:8011/ready | python -c "import sys,json;print(json.load(sys.stdin).get('model_loaded'))")" "True"
T_READY=$(date +%s)

step "First user journey through the API (CSRF cookie -> register -> login -> authenticated read)"
curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" -H "Origin: http://localhost:3000" >/dev/null
expect "register" "$(api POST /auth/register '{"name":"Clean Start","email":"clean@university.edu","password":"CleanStart#2026","password_confirmation":"CleanStart#2026","department":"CSE","designation":"Lecturer"}')" "201"
expect "logout" "$(api POST /auth/logout '{}')" "200"
expect "login" "$(api POST /auth/login '{"email":"clean@university.edu","password":"CleanStart#2026"}')" "200"
expect "authenticated /auth/user" "$(api GET /auth/user)" "200"
api GET /courses >/dev/null; expect "new account has no courses" "$(python -c "import sys,json;print(len(json.load(sys.stdin)['data']))" < "$TMP_BODY")" "0"
expect "logout" "$(api POST /auth/logout '{}')" "200"
expect "seeded faculty login" "$(api POST /auth/login '{"email":"faculty@example.com","password":"password123"}')" "200"
api GET /courses >/dev/null; expect "seeded courses visible" "$(python -c "import sys,json;print(len(json.load(sys.stdin)['data'])>0)" < "$TMP_BODY")" "True"

step "Queue worker + scheduler + integrity"
expect "queue worker running" "$(dc ps --status running --services 2>/dev/null | grep -c '^queue-worker$')" "1"
dc exec -T app php artisan schedule:list 2>/dev/null | sed 's/^/  /' | head -5
expect "integrity issues on fresh seed" "$(dc exec -T app php artisan facultylens:integrity-check --json 2>/dev/null | python -c "import sys,json;print(json.load(sys.stdin)['issues'])")" "0"
expect "no failed jobs" "$(sqlq 'SELECT COUNT(*) FROM failed_jobs')" "0"

step "Container logs: no PHP fatals / unhandled exceptions once the schema exists"
PRE=$(dc logs --until "$MIGRATED_AT" queue-worker 2>&1 | grep -ciE "SQLSTATE|fatal" || true)
echo "  note: queue-worker log lines before migrations ran (expected on a truly empty database, it retries): $PRE"
ERR=$(dc logs --since "$MIGRATED_AT" app queue-worker ai-service 2>&1 | grep -iE "fatal error|unhandled|traceback|SQLSTATE" || true)
[[ -n "$ERR" ]] && echo "$ERR" | head -5 | cut -c1-200 | sed 's/^/  | /'
expect "error lines in logs" "$(printf '%s' "$ERR" | grep -c . || true)" "0"

T1=$(date +%s)
echo
echo "== Timing: build+up $((T_UP-T0))s · to ready $((T_READY-T_UP))s · total $((T1-T0))s"
if [[ $FAILS -eq 0 ]]; then echo "== CLEAN START TEST PASSED"; else echo "== CLEAN START TEST FAILED: $FAILS expectation(s)"; exit 1; fi
