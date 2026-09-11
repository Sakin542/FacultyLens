#!/usr/bin/env bash
# Probe which login inputs the backend rejects with 422 (validation) vs 401 (bad credentials).
set -u
BASE="${BASE:-http://localhost:8080}"; O="${ORIGIN:-http://localhost:3000}"; JAR=$(mktemp)
curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" -H "Origin: $O" -H "Referer: $O/" >/dev/null
X=$(grep XSRF-TOKEN "$JAR" | awk '{print $7}' | python -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))")
probe() { # json
  printf '%-60s -> ' "$1"
  curl -s -b "$JAR" -w " [%{http_code}]\n" -X POST "$BASE/api/auth/login" -H "Accept: application/json" -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $X" -H "Origin: $O" -H "Referer: $O/" -d "$1" | head -c 160; echo
}
probe '{"email":"nobody@university.edu","password":"WrongPassword1"}'
probe '{"email":"nobody@university","password":"WrongPassword1"}'
probe '{"email":"nobody@university.e","password":"WrongPassword1"}'
probe '{"email":"no body@university.edu","password":"WrongPassword1"}'
probe '{"email":"nobody@university.edu.","password":"WrongPassword1"}'
probe '{"email":"nobody@@university.edu","password":"WrongPassword1"}'
probe '{"email":"nobody@university.edu","password":""}'
probe '{"email":"","password":"x"}'
probe '{"email":"Nobody@University.EDU","password":"WrongPassword1"}'
