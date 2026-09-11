#!/usr/bin/env bash
# Simulates the SPA on an arbitrary local port (default 3001): CSRF cookie -> register -> /api/auth/user
set -u
BASE="${BASE:-http://localhost:8080}"
O="${ORIGIN:-http://localhost:3001}"
JAR=$(mktemp)
E="cors.$(date +%s)@university.edu"
P='Password123!'
xsrf() { grep XSRF-TOKEN "$JAR" | awk '{print $7}' | python -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))"; }
curl -s -c "$JAR" "$BASE/sanctum/csrf-cookie" -H "Origin: $O" -H "Referer: $O/" >/dev/null
printf 'register -> '
curl -s -b "$JAR" -c "$JAR" -X POST "$BASE/api/auth/register" -H "Accept: application/json" -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $(xsrf)" -H "Origin: $O" -H "Referer: $O/" \
  -d "{\"name\":\"CORS\",\"email\":\"$E\",\"password\":\"$P\",\"password_confirmation\":\"$P\",\"department\":\"CSE\",\"designation\":\"Lecturer\"}" | head -c 60; echo
printf 'auth/user -> '
curl -s -b "$JAR" -w " [%{http_code}]\n" "$BASE/api/auth/user" -H "Accept: application/json" -H "X-XSRF-TOKEN: $(xsrf)" -H "Origin: $O" -H "Referer: $O/" | head -c 140; echo
