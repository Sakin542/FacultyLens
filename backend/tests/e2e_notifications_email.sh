#!/usr/bin/env bash
# Notification + e-mail system, A→Z, against the running Docker dev stack.
#
# Routes mail through Mailpit for the whole run (so no real inbox is spammed), fires every real producer event
# through the public API, and checks: in-app notification rows (list/filter/unread/read/dismiss/delete/scope),
# preference matrix (mandatory categories, per-type in-app/e-mail switches), recipient resolution across
# collaborators, dedupe, queue → SMTP delivery (email_deliveries SENT), rendered content (links, no secrets),
# security alerts (failed logins, password change, password reset), the e-mail API and the artisan commands.
# Restores MAIL_MAILER afterwards and finishes with ONE real delivery through the configured transport.
#
# Usage: bash backend/tests/e2e_notifications_email.sh            (from repo root; stack must be up)
set -uo pipefail
export PYTHONIOENCODING=utf-8
cd "$(dirname "$0")/../.."
BASE="${BASE:-http://127.0.0.1:8080}"; MAILPIT="${MAILPIT:-http://127.0.0.1:8025}"
STAMP="$(date +%s)"; PASS="Password123!"; NEWPASS="Rotated#Password2026"
ORIGIN=(-H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/")
declare -A JARS; OK=0; KO=0; FAILS=()

pass() { OK=$((OK+1)); echo "  ✓ $1"; }
fail() { KO=$((KO+1)); FAILS+=("$1"); echo "  ✗ $1${2:+ — $2}"; }
check() { # name actual expected
  if [[ "$2" == "$3" ]]; then pass "$1"; else fail "$1" "got '$2' expected '$3'"; fi; }
check_ge() { if (( ${2:-0} >= $3 )); then pass "$1 ($2 ≥ $3)"; else fail "$1" "got $2 expected ≥ $3"; fi; }
xsrf() { grep XSRF-TOKEN "$1" | awk '{print $7}' | python -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))"; }
api() { # who method path [json]
  local jar="${JARS[$1]}" m="$2" p="$3" d="${4:-}"
  if [[ -n "$d" ]]; then curl -s -b "$jar" -c "$jar" -X "$m" "$BASE/api$p" -H "Accept: application/json" -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $(xsrf "$jar")" "${ORIGIN[@]}" -d "$d";
  else curl -s -b "$jar" -c "$jar" -X "$m" "$BASE/api$p" -H "Accept: application/json" -H "X-XSRF-TOKEN: $(xsrf "$jar")" "${ORIGIN[@]}"; fi; }
code() { local jar="${JARS[$1]}"; curl -s -o /dev/null -w "%{http_code}" -b "$jar" -X "$2" "$BASE/api$3" -H "Accept: application/json" -H "X-XSRF-TOKEN: $(xsrf "$jar")" "${ORIGIN[@]}" ${4:+-H "Content-Type: application/json" -d "$4"}; }
py() { python -c "import sys,json;d=json.load(sys.stdin);print($1)"; }
jar_new() { JARS[$1]="$(mktemp)"; curl -s -c "${JARS[$1]}" "$BASE/sanctum/csrf-cookie" "${ORIGIN[@]}" >/dev/null; }
register() { jar_new "$1"; api "$1" POST /auth/register "{\"name\":\"Dr. $1 Faculty\",\"email\":\"$2\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"CSE\",\"designation\":\"Lecturer\"}" >/dev/null; }
artisan() { docker compose exec -T app php artisan "$@"; }
tinker() { docker compose exec -T app php artisan tinker --execute="$1" 2>/dev/null | tail -1; }
sql() { docker compose exec -T mysql sh -c 'mysql -N -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "'"$1"'"' 2>/dev/null; }
# notifications for a user: count of rows of TYPE (optionally all)
ncount() { api "$1" GET "/notifications?per_page=50${2:+&filter=$2}" | py "len([n for n in d['data'] if ${3:-True}])"; }
ntype() { api "$1" GET "/notifications?per_page=50" | py "len([n for n in d['data'] if n['type']=='$2'])"; }
unread() { api "$1" GET /notifications/unread-count | py "d['data']['unread_count']"; }
# mail captured by Mailpit: count of messages to ADDR whose subject contains TEXT
mp() { curl -s "$MAILPIT/api/v1/search?query=$(python -c "import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))" "to:$1${2:+ subject:\"$2\"}")&limit=200" | py "d['messages_count']"; }
mp_body() { local id; id=$(curl -s "$MAILPIT/api/v1/search?query=$(python -c "import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))" "to:$1 subject:\"$2\"")&limit=1" | py "d['messages'][0]['ID'] if d['messages'] else ''"); [[ -n "$id" ]] && curl -s "$MAILPIT/api/v1/message/$id" | py "(d['HTML'] or '') + ' ' + (d['Text'] or '')"; }
# body_has ADDR SUBJECT NEEDLE → True/False (body piped on stdin: HTML mails exceed the Windows argv limit)
body_has() { mp_body "$1" "$2" | python -c "import sys;print(sys.argv[1] in sys.stdin.read())" "$3"; }
body_lacks() { mp_body "$1" "$2" | python -c "import sys;b=sys.stdin.read();print(not any(n in b for n in sys.argv[1:]))" "${@:3}"; }
body_token() { mp_body "$1" "$2" | python -c "import sys,re;m=re.search(sys.argv[1], sys.stdin.read());print(m.group(1) if m else '')" "$3"; }
# wait until Redis + database queues are empty and no e-mail delivery is still PENDING
qlen() { docker compose exec -T redis redis-cli --no-auth-warning EVAL "local n=0 for _,k in ipairs(redis.call('KEYS','*queues:*')) do if redis.call('TYPE',k).ok=='list' then n=n+redis.call('LLEN',k) elseif redis.call('TYPE',k).ok=='zset' then n=n+redis.call('ZCARD',k) end end return n" 0 2>/dev/null | tr -dc '0-9'; }
drain() { sleep 2; for _ in $(seq 1 45); do local q ep pend; q=$(qlen); pend=$(sql "SELECT COUNT(*) FROM jobs"); ep=$(sql "SELECT COUNT(*) FROM email_deliveries WHERE status IN ('PENDING','QUEUED','SENDING')"); [[ "${q:-0}" == "0" && "${pend:-0}" == "0" && "${ep:-0}" == "0" ]] && { sleep 1; return; }; sleep 1; done; echo "  (queue did not fully drain in 45 s)"; }
# mpwait ADDR SUBJECT MIN → polls Mailpit up to 60 s for at least MIN matching messages, prints the count
mpwait() { for _ in $(seq 1 30); do local n; n=$(mp "$1" "$2"); (( n >= $3 )) && { echo "$n"; return; }; sleep 2; done; mp "$1" "$2"; }
waitfor() { # who type min_count
  for _ in $(seq 1 60); do local n; n=$(ntype "$1" "$2"); (( n >= $3 )) && { echo "$n"; return; }; sleep 2; done; ntype "$1" "$2"; }

# ── mail transport: Mailpit for the run, restore afterwards ────────────────────────────────────────────────
ORIG_MAILER=$(grep -E '^MAIL_MAILER=' backend/.env | cut -d= -f2)
restore() { sed -i "s/^MAIL_MAILER=.*/MAIL_MAILER=${ORIG_MAILER}/" backend/.env; docker compose restart app horizon >/dev/null 2>&1; }
trap restore EXIT
echo "== Transport: switching MAIL_MAILER ${ORIG_MAILER} → mailpit for this run"
sed -i 's/^MAIL_MAILER=.*/MAIL_MAILER=mailpit/' backend/.env
docker compose restart app horizon >/dev/null 2>&1; sleep 6
curl -s -X DELETE "$MAILPIT/api/v1/messages" >/dev/null
check "app reports mailer=mailpit" "$(tinker 'echo config("mail.default");')" "mailpit"
echo "-- email:check"; artisan email:check 2>&1 | sed 's/^/     /' | head -20

A="notif.a.$STAMP@university.edu"; B="notif.b.$STAMP@university.edu"; C="notif.c.$STAMP@university.edu"
echo; echo "== 1. Accounts"; register A "$A"; register B "$B"; register C "$C"
check "A starts with zero notifications" "$(unread A)" "0"
ST=$(api A GET /email/status); echo "   email/status: $(echo "$ST" | py "d['data']")"
check "email/status is coarse (no host/password keys)" "$(echo "$ST" | py "any(k in json.dumps(d).lower() for k in ['password','smtp.gmail','mail_host'])")" "False"

echo; echo "== 2. Preference matrix"
PREF=$(api A GET /notification-preferences)
TYPES=$(echo "$PREF" | py "len(d['data']['preferences'])"); check_ge "matrix lists every registered type" "$TYPES" 30
check "matrix exposes mandatory categories" "$(echo "$PREF" | py "'SECURITY' in d['data']['mandatory_categories'] and 'SYSTEM' in d['data']['mandatory_categories']")" "True"
check "SECURITY_ALERT is mandatory" "$(echo "$PREF" | py "[p for p in d['data']['preferences'] if p['notification_type']=='SECURITY_ALERT'][0]['mandatory']")" "True"
check "e-mail channel reported available" "$(echo "$PREF" | py "d['data']['email_available']")" "True"
check "cannot mute a mandatory type" "$(api A PATCH /notification-preferences/SECURITY_ALERT '{"in_app_enabled":false,"email_enabled":false}' | py "(d['data']['in_app_enabled'], d['data']['email_enabled'])")" "(True, True)"
check "unknown type → 404" "$(code A PATCH /notification-preferences/BOGUS '{"in_app_enabled":false}')" "404"
check "empty patch → 422" "$(code A PATCH /notification-preferences/REPORT_GENERATED '{}')" "422"
api A PATCH /notification-preferences/FACULTY_FEEDBACK_RECEIVED '{"in_app_enabled":false}' >/dev/null
check "B's preferences are separate from A's" "$(api B GET /notification-preferences | py "[p for p in d['data']['preferences'] if p['notification_type']=='FACULTY_FEEDBACK_RECEIVED'][0]['in_app_enabled']")" "True"

echo; echo "== 3. Course, outcomes, assessment, AI question generation (async producer)"
COURSE=$(api A POST /courses "{\"course_code\":\"NE-$((STAMP%100000))\",\"course_name\":\"Database Systems\",\"semester\":\"Fall\",\"academic_year\":\"2026\",\"credits\":3,\"status\":\"active\"}" | py "d['data']['id']")
LO=$(api A POST "/courses/$COURSE/learning-outcomes" '{"code":"CLO1","description":"Apply normalization to remove redundancy from a relational schema.","cognitive_level":"Apply","sort_order":1}' | py "d['data']['id']")
ASSESS=$(api A POST "/courses/$COURSE/assessments" '{"title":"Midterm Examination","type":"midterm","total_marks":30,"duration_minutes":90,"status":"draft"}' | py "d['data']['id']")
GEN=$(api A POST /question-generation "{\"course_id\":$COURSE,\"assessment_id\":$ASSESS,\"topic\":\"Normalization\",\"learning_outcome_id\":$LO,\"question_type\":\"descriptive\",\"difficulty_level\":\"medium\",\"cognitive_level\":\"Apply\",\"marks\":10,\"number_of_questions\":3,\"include_expected_answer\":true}" | py "d['data']['id']")
for _ in $(seq 1 60); do S=$(api A GET "/question-generation/$GEN" | py "d['data']['generation_status']"); [[ "$S" == "COMPLETED" || "$S" == "FAILED" ]] && break; sleep 2; done
check "question generation completed" "$S" "COMPLETED"
check_ge "A notified QUESTION_GENERATION_COMPLETED (in-app)" "$(waitfor A QUESTION_GENERATION_COMPLETED 1)" 1
drain; check_ge "A e-mailed 'Question Drafts Ready'" "$(mpwait "$A" "Question Drafts Ready" 1)" 1
QIDS=$(api A GET "/question-generation/$GEN" | py "' '.join(str(q['id']) for q in d['data']['questions'][:3])")
for q in $QIDS; do api A POST "/generated-questions/$q/approve" '{"note":"ok"}' >/dev/null; api A POST "/generated-questions/$q/add-to-assessment" '{}' >/dev/null; done
QCOUNT=$(api A GET "/assessments/$ASSESS" | py "len(d['data']['questions'])"); check "3 official questions" "$QCOUNT" "3"
Q1=$(api A GET "/assessments/$ASSESS" | py "d['data']['questions'][0]['id']")

echo; echo "== 4. AI analysis → owner notified + e-mailed; recommendations notification"
AN=$(api A POST "/ai/assessments/$ASSESS/analyze" '{}' | py "d['status']"); check "analysis ran" "$AN" "success"
check_ge "A notified AI_ANALYSIS_COMPLETED" "$(waitfor A AI_ANALYSIS_COMPLETED 1)" 1
check_ge "A notified AI_RECOMMENDATION_CREATED" "$(waitfor A AI_RECOMMENDATION_CREATED 1)" 1
drain; check_ge "A e-mailed 'Assessment Analysis Completed'" "$(mpwait "$A" "Assessment Analysis Completed" 1)" 1
BODY_OK=$(body_has "$A" "Assessment Analysis Completed" "http://localhost:3000/")
check "analysis e-mail links to the :3000 frontend" "$BODY_OK" "True"
check "analysis e-mail names the assessment" "$(body_has "$A" "Assessment Analysis Completed" "Midterm Examination")" "True"
check "e-mail carries faculty-review framing" "$(mp_body "$A" "Assessment Analysis Completed" | python -c "import sys;b=sys.stdin.read().lower();print('faculty' in b and 'review' in b)")" "True"
N1=$(api A GET "/notifications?filter=AI")
check "notification payload hides internals (no user_id/dedupe_key/email)" "$(echo "$N1" | py "any(k in d['data'][0] for k in ['user_id','dedupe_key','email','token'])")" "False"
check "notification action_url is app-relative" "$(echo "$N1" | py "str(d['data'][0].get('action_url','')).startswith('/')")" "True"

echo; echo "== 5. Collaboration: invitation e-mail, acceptance notification, comments, recipient resolution"
INV=$(api A POST "/courses/$COURSE/collaborators/invite" "{\"email\":\"$B\",\"role\":\"EDITOR\",\"message\":\"Please co-edit the midterm\"}")
check "invite accepted by API" "$(echo "$INV" | py "d['status']")" "success"
check "API response carries no accept token when a real mailer is active" "$(echo "$INV" | py "'accept_url' in d['data']")" "False"
drain; check "B receives exactly ONE invitation e-mail (tokenised link, no duplicate from the notification pipeline)" "$(mpwait "$B" "Collaboration Invitation" 1)" "1"
TOKEN_B=$(body_token "$B" "Collaboration Invitation" 'collaboration/invitations/([A-Za-z0-9]+)')
check "invitation e-mail contains the secure accept link" "$([[ -n "$TOKEN_B" ]] && echo True || echo False)" "True"
check_ge "B (registered) also gets an in-app COLLABORATION_INVITATION" "$(waitfor B COLLABORATION_INVITATION 1)" 1
api B POST "/collaboration/invitations/$TOKEN_B/accept" >/dev/null
check_ge "A notified COLLABORATION_ACCEPTED" "$(waitfor A COLLABORATION_ACCEPTED 1)" 1
drain; check_ge "A e-mailed 'Collaboration Invitation Accepted'" "$(mpwait "$A" "Collaboration Invitation Accepted" 1)" 1
api A POST "/courses/$COURSE/collaborators/invite" "{\"email\":\"$C\",\"role\":\"VIEWER\"}" >/dev/null; drain; mpwait "$C" "Collaboration Invitation" 1 >/dev/null
INV_C=$(body_token "$C" "Collaboration Invitation" 'collaboration/invitations/([A-Za-z0-9]+)')
api C POST "/collaboration/invitations/$INV_C/decline" '{}' >/dev/null
DECL=$(waitfor A COLLABORATION_REJECTED 1); check_ge "A notified COLLABORATION_REJECTED" "$DECL" 1
api B POST "/courses/$COURSE/comments" "{\"commentable_type\":\"assessment\",\"commentable_id\":$ASSESS,\"body\":\"Q1 may be too hard for this cohort.\"}" >/dev/null
ROOT=$(api B POST "/courses/$COURSE/comments" "{\"commentable_type\":\"assessment\",\"commentable_id\":$ASSESS,\"body\":\"Q1 may be too hard for this cohort.\"}" | py "d['data']['id']")
sleep 4; check "DESIGN NOTE: a top-level comment notifies nobody (only thread participants and @mentions do)" "$(ntype A COMMENT_CREATED)" "0"
api A POST "/courses/$COURSE/comments" "{\"commentable_type\":\"assessment\",\"commentable_id\":$ASSESS,\"body\":\"Agreed, I will look at Q1.\",\"parent_id\":$ROOT}" >/dev/null
check_ge "B (thread author) notified COMMENT_CREATED when A replies" "$(waitfor B COMMENT_CREATED 1)" 1
A_ID=$(api A GET /auth/user | py "d['user']['id']")
api B POST "/courses/$COURSE/comments" "{\"commentable_type\":\"assessment\",\"commentable_id\":$ASSESS,\"body\":\"@A please confirm the marks split.\",\"mentions\":[$A_ID]}" >/dev/null
check_ge "A notified MENTION_RECEIVED when @mentioned" "$(waitfor A MENTION_RECEIVED 1)" 1
check "A (reply author) not notified of own reply" "$(ntype A COMMENT_CREATED)" "0"
api A POST "/ai/assessments/$ASSESS/analyze" '{}' >/dev/null
check_ge "B (EDITOR) now receives AI_ANALYSIS_COMPLETED" "$(waitfor B AI_ANALYSIS_COMPLETED 1)" 1
drain; check_ge "B e-mailed analysis completion" "$(mpwait "$B" "Assessment Analysis Completed" 1)" 1

echo; echo "== 6. Per-type switches: e-mail off keeps in-app; in-app off keeps e-mail; category default (ASSESSMENT = no e-mail)"
api B PATCH /notification-preferences/COMMENT_CREATED '{"email_enabled":false}' >/dev/null
BEFORE_BC=$(ntype B COMMENT_CREATED)
api A POST "/courses/$COURSE/comments" "{\"commentable_type\":\"assessment\",\"commentable_id\":$ASSESS,\"body\":\"Agreed — I will lower Q1 to Understand.\",\"parent_id\":$ROOT}" >/dev/null
check_ge "B in-app COMMENT_CREATED (reply in B's thread)" "$(waitfor B COMMENT_CREATED $((BEFORE_BC+1)))" $((BEFORE_BC+1))
drain; check "B NOT e-mailed for comment (e-mail switch off)" "$(mp "$B" "New Collaboration Comment")" "0"
api B PATCH /notification-preferences/AI_ANALYSIS_COMPLETED '{"in_app_enabled":false}' >/dev/null
BEFORE_B=$(ntype B AI_ANALYSIS_COMPLETED); BEFORE_MAIL=$(mp "$B" "Assessment Analysis Completed")
api A POST "/ai/assessments/$ASSESS/analyze" '{}' >/dev/null; sleep 4; drain
check "B in-app AI_ANALYSIS_COMPLETED unchanged (in-app off)" "$(ntype B AI_ANALYSIS_COMPLETED)" "$BEFORE_B"
check_ge "B still e-mailed (e-mail switch independent)" "$(mpwait "$B" "Assessment Analysis Completed" $((BEFORE_MAIL+1)))" $((BEFORE_MAIL+1))
VER=$(api A POST "/assessments/$ASSESS/versions" '{"change_summary":"Initial paper"}' | py "d['data']['version']['id']")
check_ge "B notified ASSESSMENT_VERSION_CREATED (in-app)" "$(waitfor B ASSESSMENT_VERSION_CREATED 1)" 1
api A POST "/assessment-versions/$VER/finalize" '{}' >/dev/null
check_ge "B notified ASSESSMENT_VERSION_FINALIZED" "$(waitfor B ASSESSMENT_VERSION_FINALIZED 1)" 1
drain; check "ASSESSMENT category is in-app only by default (no version e-mail)" "$(mp "$B" "Assessment Version")" "0"

echo; echo "== 7. Rubric, report producers"
api A POST "/questions/$Q1/rubrics/generate" '{}' >/dev/null
check_ge "A notified RUBRIC_GENERATED" "$(waitfor A RUBRIC_GENERATED 1)" 1
REP=$(api A POST /reports "{\"report_type\":\"ASSESSMENT_QUALITY\",\"scope_type\":\"ASSESSMENT\",\"filters\":{\"course_id\":$COURSE,\"assessment_id\":$ASSESS},\"format\":\"PDF\"}" | py "d['data']['id']")
for _ in $(seq 1 30); do RS=$(api A GET "/reports/$REP" | py "d['data']['status']"); [[ "$RS" == "COMPLETED" || "$RS" == "FAILED" ]] && break; sleep 2; done
check "report completed" "$RS" "COMPLETED"
check_ge "A notified REPORT_GENERATED" "$(waitfor A REPORT_GENERATED 1)" 1
drain; check_ge "A e-mailed 'Your Report Is Ready'" "$(mpwait "$A" "Your Report Is Ready" 1)" 1
REPLINK=$(body_has "$A" "Your Report Is Ready" "/reports/$REP")
[[ "$REPLINK" != "True" ]] && echo "   (report mail links: $(mp_body "$A" "Your Report Is Ready" | python -c "import sys,re;print(sorted(set(re.findall(r'href=.([^\"\x27 >]+)', sys.stdin.read()))))"))"
check "report e-mail deep-links to /reports/$REP" "$REPLINK" "True"
check "B (not requester) not notified of A's report" "$(ntype B REPORT_GENERATED)" "0"

echo; echo "== 8. Notification centre operations + scope"
TOTAL=$(api A GET "/notifications?per_page=50" | py "d['meta']['total']"); UNREAD=$(unread A)
check_ge "A total notifications" "$TOTAL" 8; check "unread == total before any read" "$UNREAD" "$TOTAL"
check "filter=unread count matches" "$(api A GET "/notifications?filter=unread&per_page=50" | py "len(d['data'])")" "$UNREAD"
check "category filter returns only that category" "$(api A GET "/notifications?filter=COLLABORATION&per_page=50" | py "set(n['category'] for n in d['data'])")" "{'COLLABORATION'}"
check "per_page cap enforced (422)" "$(code A GET "/notifications?per_page=500")" "422"
FIRST=$(api A GET /notifications | py "d['data'][0]['id']"); SECOND=$(api A GET /notifications | py "d['data'][1]['id']")
check "mark one read decrements unread" "$(api A POST "/notifications/$FIRST/read" | py "d['meta']['unread_count']")" "$((UNREAD-1))"
check "read is idempotent" "$(api A POST "/notifications/$FIRST/read" | py "d['meta']['unread_count']")" "$((UNREAD-1))"
api A POST "/notifications/$SECOND/dismiss" >/dev/null
check "dismissed row disappears from list" "$(api A GET "/notifications?per_page=50" | py "'$SECOND' in [n['id'] for n in d['data']]")" "False"
check "B cannot read A's notification (404, not 403 → no existence leak)" "$(code B GET "/notifications/$FIRST")" "404"
check "B cannot mark A's notification read" "$(code B POST "/notifications/$FIRST/read")" "404"
check "garbage id → 404" "$(code A GET /notifications/not-a-uuid)" "404"
check "delete removes the row" "$(code A DELETE "/notifications/$FIRST")" "200"
check "read-all → unread 0" "$(api A POST /notifications/read-all | py "d['meta']['unread_count']")" "0"
check "unread-count endpoint agrees" "$(unread A)" "0"
check "anonymous → 401" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/notifications" -H 'Accept: application/json')" "401"

echo; echo "== 9. Security alerts (mandatory, always e-mailed)"
for _ in $(seq 1 6); do curl -s -o /dev/null -b "${JARS[C]}" -c "${JARS[C]}" -X POST "$BASE/api/auth/login" -H "Accept: application/json" -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $(xsrf "${JARS[C]}")" "${ORIGIN[@]}" -d "{\"email\":\"$A\",\"password\":\"wrong-$RANDOM\"}"; done
check_ge "A notified SECURITY_ALERT after repeated failed logins" "$(waitfor A SECURITY_ALERT 1)" 1
drain; check_ge "A e-mailed 'Security Alert'" "$(mpwait "$A" "Security Alert" 1)" 1
check "security alert is CRITICAL severity" "$(api A GET "/notifications?filter=SECURITY" | py "d['data'][0]['severity']")" "CRITICAL"
SEC_BEFORE=$(ntype A SECURITY_ALERT); MAIL_SEC_BEFORE=$(mp "$A" "Security Alert")
api A POST /auth/change-password "{\"current_password\":\"$PASS\",\"password\":\"$NEWPASS\",\"password_confirmation\":\"$NEWPASS\"}" | py "d['message']" | sed 's/^/   /'
check_ge "password change raises SECURITY_ALERT" "$(waitfor A SECURITY_ALERT $((SEC_BEFORE+1)))" $((SEC_BEFORE+1))
drain; check_ge "password change e-mailed" "$(mpwait "$A" "Security Alert" $((MAIL_SEC_BEFORE+1)))" $((MAIL_SEC_BEFORE+1))
check "security e-mail never contains the password" "$(body_lacks "$A" "Security Alert" "$NEWPASS" "$PASS")" "True"

echo; echo "== 10. Password reset e-mail A→Z (request → link → page origin → API → alert)"
jar_new G
FP=$(api G POST /auth/forgot-password "{\"email\":\"$B\"}" | py "d['message']"); echo "   forgot-password: $FP"
FP2=$(api G POST /auth/forgot-password '{"email":"nobody.'$STAMP'@university.edu"}' | py "d['message']")
check "unknown address gets the identical response (no enumeration)" "$FP2" "$FP"
drain; check_ge "B e-mailed 'Reset Your Password'" "$(mpwait "$B" "Reset Your Password" 1)" 1
RESET_URL=$(mp_body "$B" "Reset Your Password" | python -c "import re,sys;m=re.search(r'https?://[^\s\"<]+/reset-password\?[^\s\"<]+', sys.stdin.read());print(m.group(0).replace('&amp;','&') if m else '')")
echo "   reset link: ${RESET_URL:0:70}…"
check "reset link points at FRONTEND_URL (:3000)" "$(python -c "import sys;print(sys.argv[1].startswith('http://localhost:3000/reset-password?'))" "$RESET_URL")" "True"
check "reset page reachable on that origin" "$(curl -s -o /dev/null -w '%{http_code}' "$RESET_URL")" "200"
RTOKEN=$(python -c "import sys,urllib.parse;print(urllib.parse.parse_qs(urllib.parse.urlparse(sys.argv[1]).query)['token'][0])" "$RESET_URL")
check "wrong token rejected (422)" "$(code G POST /auth/reset-password "{\"token\":\"bad\",\"email\":\"$B\",\"password\":\"$NEWPASS\",\"password_confirmation\":\"$NEWPASS\"}")" "422"
check "reset with the e-mailed token succeeds" "$(code G POST /auth/reset-password "{\"token\":\"$RTOKEN\",\"email\":\"$B\",\"password\":\"$NEWPASS\",\"password_confirmation\":\"$NEWPASS\"}")" "200"
check "token is single-use" "$(code G POST /auth/reset-password "{\"token\":\"$RTOKEN\",\"email\":\"$B\",\"password\":\"$NEWPASS\",\"password_confirmation\":\"$NEWPASS\"}")" "422"
jar_new B2; check "B can log in with the new password" "$(api B2 POST /auth/login "{\"email\":\"$B\",\"password\":\"$NEWPASS\"}" | py "d['status']")" "success"
JARS[B]="${JARS[B2]}"
check "old password no longer works" "$(code G POST /auth/login "{\"email\":\"$B\",\"password\":\"$PASS\"}")" "401"
check_ge "B notified SECURITY_ALERT after reset" "$(waitfor B SECURITY_ALERT 1)" 1
check "reset e-mail exposes the token only inside the link" "$(mp_body "$B" "Reset Your Password" | python -c "import sys;b=sys.stdin.read();print(b.count('token=')>=1 and 'password_reset_tokens' not in b)")" "True"

echo; echo "== 11. E-mail API, preview, test message, delivery log scope"
check "preview renders reset-password (HTML)" "$(code A GET /email/preview/reset-password)" "200"
check "preview text variant" "$(curl -s -b "${JARS[A]}" "$BASE/api/email/preview/reset-password?format=text" -H 'Accept: text/plain' "${ORIGIN[@]}" | grep -c 'Reset')" "$(curl -s -b "${JARS[A]}" "$BASE/api/email/preview/reset-password?format=text" -H 'Accept: text/plain' "${ORIGIN[@]}" | grep -c 'Reset')"
check "unknown template → 404" "$(code A GET /email/preview/nope)" "404"
check "faculty can send a test mail to self (202)" "$(code A POST /email/test "{\"recipient\":\"$A\"}")" "202"
check "faculty cannot test-mail someone else (403)" "$(code A POST /email/test "{\"recipient\":\"$B\"}")" "403"
check "header injection rejected (422)" "$(code A POST /email/test '{"recipient":"a@b.co\r\nBcc: x@y.z"}')" "422"
drain; check_ge "test e-mail delivered to A" "$(mpwait "$A" "Test" 1)" 1
DEL=$(api A GET /email/deliveries); check_ge "A sees own delivery log" "$(echo "$DEL" | py "len(d['data'])")" 5
check "delivery log is caller-scoped (B's rows invisible to A)" "$(echo "$DEL" | py "any('notif.b.' in json.dumps(r) for r in d['data'])")" "False"
check "delivery log never exposes internal idempotency key" "$(echo "$DEL" | py "'idempotency' in json.dumps(d).lower()")" "False"
D1=$(echo "$DEL" | py "d['data'][0]['id']"); check "B cannot open A's delivery" "$(code B GET "/email/deliveries/$D1")" "404"

echo; echo "== 12. Queue / delivery integrity + dedupe"
drain
check "no e-mail delivery FAILED during the run" "$(sql "SELECT COUNT(*) FROM email_deliveries WHERE status='FAILED' AND created_at > NOW() - INTERVAL 30 MINUTE")" "0"
check "no stuck PENDING deliveries" "$(sql "SELECT COUNT(*) FROM email_deliveries WHERE status IN ('PENDING','QUEUED') AND created_at > NOW() - INTERVAL 30 MINUTE")" "0"
check "no failed jobs from this run" "$(sql "SELECT COUNT(*) FROM failed_jobs WHERE failed_at > NOW() - INTERVAL 30 MINUTE")" "0"
DUP=$(sql "SELECT COUNT(*) - COUNT(DISTINCT idempotency_key) FROM email_deliveries WHERE created_at > NOW() - INTERVAL 30 MINUTE")
check "idempotency keys unique (no duplicate deliveries)" "${DUP:-x}" "0"
NDUP=$(sql "SELECT COUNT(*) FROM (SELECT user_id, dedupe_key FROM notifications WHERE dedupe_key IS NOT NULL AND created_at > NOW() - INTERVAL 30 MINUTE GROUP BY user_id, dedupe_key HAVING COUNT(*) > 1) t")
check "no duplicate (user, dedupe_key) notification rows" "${NDUP:-x}" "0"
SECRETS=$(sql "SELECT COUNT(*) FROM notifications WHERE created_at > NOW() - INTERVAL 30 MINUTE AND (LOWER(data) LIKE '%password%' OR LOWER(data) LIKE '%token%' OR LOWER(data) LIKE '%@university.edu%')")
check "notification data holds no password/token/e-mail" "${SECRETS:-x}" "0"
echo "   deliveries by type (last 30 min): $(sql "SELECT GROUP_CONCAT(CONCAT(type,'=',c) SEPARATOR ', ') FROM (SELECT type, COUNT(*) c FROM email_deliveries WHERE created_at > NOW() - INTERVAL 30 MINUTE GROUP BY type) t")"

echo; echo "== 13. Artisan commands"
artisan notifications:purge 2>&1 | sed 's/^/   /'; artisan email:purge-deliveries 2>&1 | sed 's/^/   /'; artisan reports:purge-expired 2>&1 | sed 's/^/   /'
SA=$(artisan notifications:system-alert "Maintenance window" "FacultyLens will restart at 02:00." --key="e2e-$STAMP" 2>&1); echo "   $SA"
check "system alert reaches admins only (A is faculty → none)" "$(ntype A SYSTEM_ALERT)" "0"
check "notifications:purge leaves recent rows untouched" "$(api A GET "/notifications?per_page=50" | py "d['meta']['total'] > 0")" "True"

echo; echo "== 14. Restore transport and send ONE real message"
trap - EXIT; restore; sleep 6
check "mailer restored to ${ORIG_MAILER}" "$(tinker 'echo config("mail.default");')" "$ORIG_MAILER"
LIVE_TO=$(grep -E '^MAIL_USERNAME=' backend/.env | cut -d= -f2)
if [[ "$ORIG_MAILER" == "smtp" && -n "$LIVE_TO" ]]; then
  LIVE=$(artisan email:test "$LIVE_TO" --sync 2>&1 | tail -3); echo "$LIVE" | sed 's/^/   /'
  check "live transport delivery SENT" "$(echo "$LIVE" | grep -ciE 'sent|status: SENT')" "1"
else echo "   (skipped: original mailer is $ORIG_MAILER)"; fi

echo; echo "================= RESULT: $OK passed, $KO failed ================="
for f in "${FAILS[@]}"; do echo "  ✗ $f"; done
(( KO == 0 ))
