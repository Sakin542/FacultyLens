#!/usr/bin/env bash
# STEP 34 E2E: A (owner) creates CSE101 + Midterm; invites B (EDITOR) and C (REVIEWER); B accepts & comments on
# assessment; A replies & resolves; C views, comments, edit denied; C blocked on Course B (isolation); owner protection;
# activity feed; notifications. Mail driver is log → API returns accept_url.
set -euo pipefail
BASE="${BASE:-http://127.0.0.1:8080}"
STAMP="$(date +%s)"
PASS="Password123!"
ORIGIN=(-H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/")
declare -A JARS

xsrf() { grep XSRF-TOKEN "$1" | awk '{print $7}' | python -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))"; }
api() { # who method path [json]
  local jar="${JARS[$1]}" m="$2" p="$3" d="${4:-}"
  if [[ -n "$d" ]]; then curl -s -b "$jar" -c "$jar" -X "$m" "$BASE/api$p" -H "Accept: application/json" -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $(xsrf "$jar")" "${ORIGIN[@]}" -d "$d";
  else curl -s -b "$jar" -c "$jar" -X "$m" "$BASE/api$p" -H "Accept: application/json" -H "X-XSRF-TOKEN: $(xsrf "$jar")" "${ORIGIN[@]}"; fi; }
code() { local jar="${JARS[$1]}"; curl -s -o /dev/null -w "%{http_code}" -b "$jar" -X "$2" "$BASE/api$3" -H "Accept: application/json" -H "X-XSRF-TOKEN: $(xsrf "$jar")" "${ORIGIN[@]}" ${4:+-H "Content-Type: application/json" -d "$4"}; }
py() { python -c "import sys,json;d=json.load(sys.stdin);print($1)"; }
login() { # who email
  JARS[$1]="$(mktemp)"; curl -s -c "${JARS[$1]}" "$BASE/sanctum/csrf-cookie" "${ORIGIN[@]}" >/dev/null
  api "$1" POST /auth/register "{\"name\":\"Dr. $1\",\"email\":\"$2\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"department\":\"CSE\",\"designation\":\"Lecturer\"}" >/dev/null
  api "$1" POST /auth/login "{\"email\":\"$2\",\"password\":\"$PASS\"}" | head -c 80; echo; }

EMAIL_A="collab.a.$STAMP@university.edu"; EMAIL_B="collab.b.$STAMP@university.edu"; EMAIL_C="collab.c.$STAMP@university.edu"
echo "== Register A (owner), B, C"; login A "$EMAIL_A"; login B "$EMAIL_B"; login C "$EMAIL_C"

echo "== A: course + assessment; C: own course (isolation target)"
COURSE=$(api A POST /courses '{"course_code":"CSE101","course_name":"Database Systems","semester":"Fall","academic_year":"2026","credits":3,"status":"active"}' | py "d['data']['id']")
ASSESS=$(api A POST "/courses/$COURSE/assessments" '{"title":"Midterm Examination","type":"midterm","total_marks":30,"status":"draft"}' | py "d['data']['id']")
Q1=$(docker compose exec -T app php artisan tinker --execute="echo \App\Models\Question::create(['assessment_id'=>$ASSESS,'question_number'=>1,'question_text'=>'Explain normalization up to 3NF.','question_type'=>'descriptive','marks'=>10,'cognitive_level'=>'Understand'])->id;" | tr -dc '0-9')
COURSE_C=$(api C POST /courses '{"course_code":"EEE201","course_name":"Circuits","semester":"Fall","academic_year":"2026","credits":3,"status":"active"}' | py "d['data']['id']")
echo "course=$COURSE assessment=$ASSESS q1=$Q1 courseC=$COURSE_C"

echo "== Before invite: B/C cannot see CSE101"
echo -n "B course -> "; code B GET "/courses/$COURSE"; echo; echo -n "C assessment -> "; code C GET "/assessments/$ASSESS"; echo

echo "== A invites B (EDITOR) and C (REVIEWER)"
INV_B=$(api A POST "/courses/$COURSE/collaborators/invite" "{\"email\":\"$EMAIL_B\",\"role\":\"EDITOR\",\"message\":\"Please co-edit the midterm\"}")
echo "$INV_B" | py "(d['message'], d['data']['status'], d['data']['role'])"
TOKEN_B=$(echo "$INV_B" | py "d['data']['accept_url'].split('/')[-1]")
INV_C=$(api A POST "/courses/$COURSE/collaborators/invite" "{\"email\":\"$EMAIL_C\",\"role\":\"REVIEWER\"}")
TOKEN_C=$(echo "$INV_C" | py "d['data']['accept_url'].split('/')[-1]")
echo -n "duplicate pending invite -> "; code A POST "/courses/$COURSE/collaborators/invite" "{\"email\":\"$EMAIL_B\",\"role\":\"EDITOR\"}"; echo
echo -n "B (not owner) invites -> "; code B POST "/courses/$COURSE/collaborators/invite" '{"email":"x@university.edu","role":"VIEWER"}'; echo
docker compose exec -T mysql mysql -uroot -proot123 facultylens -e "SELECT id, role, status, LENGTH(token_hash) hash_len FROM course_collaboration_invitations WHERE course_id=$COURSE;" 2>/dev/null

echo "== Public preview (no auth) shows only minimal info"
curl -s "$BASE/api/collaboration/invitations/$TOKEN_B" -H "Accept: application/json" | py "(d['data']['course'], d['data']['role'], d['data']['invited_by']['name'], d['data']['invited_email'])"

echo "== B: pending invitations, accept"
api B GET /collaboration/invitations | py "[(i['course']['course_code'], i['role']) for i in d['data']]"
echo -n "C tries B's token -> "; code C POST "/collaboration/invitations/$TOKEN_B/accept"; echo
api B POST "/collaboration/invitations/$TOKEN_B/accept" | py "(d['message'], d['data']['role'], d['data']['status'])"
echo -n "token reuse -> "; code B POST "/collaboration/invitations/$TOKEN_B/accept"; echo
api C POST "/collaboration/invitations/$TOKEN_C/accept" | py "(d['data']['role'], d['data']['status'])"

echo "== B (EDITOR) opens CSE101, edits assessment; version conflict check"
api B GET "/courses/$COURSE" | py "(d['data']['current_role'], d['data']['permissions']['edit_assessment'], d['data']['permissions']['delete_course'], d['data']['permissions']['manage_collaborators'])"
api B GET /courses | py "[(c['course_code'], c['current_role']) for c in d['data']]"
UPD=$(api B GET "/assessments/$ASSESS" | py "d['data']['updated_at']")
echo -n "stale edit -> "; code B PUT "/assessments/$ASSESS" '{"title":"Midterm Examination (v2)","type":"midterm","total_marks":30,"status":"draft","expected_updated_at":"2020-01-01T00:00:00Z"}'; echo
api B PUT "/assessments/$ASSESS" "{\"title\":\"Midterm Examination (v2)\",\"type\":\"midterm\",\"total_marks\":30,\"status\":\"draft\",\"expected_updated_at\":\"$UPD\"}" | py "d['data']['title']"
echo -n "B deletes assessment -> "; code B DELETE "/assessments/$ASSESS"; echo

echo "== B comments on assessment; A replies and resolves"
ROOT=$(api B POST "/courses/$COURSE/comments" "{\"commentable_type\":\"assessment\",\"commentable_id\":$ASSESS,\"body\":\"Question 1 may be too difficult for the current cohort.\"}")
echo "$ROOT" | py "(d['data']['author']['name'], d['data']['status'])"
RID=$(echo "$ROOT" | py "d['data']['id']")
api A POST "/courses/$COURSE/comments" "{\"commentable_type\":\"assessment\",\"commentable_id\":$ASSESS,\"body\":\"Agreed. I will review the cognitive level.\",\"parent_id\":$RID}" | py "(d['data']['parent_id'], d['data']['author']['name'])"
api A GET "/courses/$COURSE/comments?commentable_type=assessment&commentable_id=$ASSESS" | py "(len(d['data']), len(d['data'][0]['replies']), d['meta']['can_comment'])"
api A POST "/comments/$RID/resolve" | py "(d['data']['status'], d['data']['resolved_by']['name'])"
api B GET /notifications | py "[(n['event'], n['title']) for n in d['data']][:3]"

echo "== C (REVIEWER): view ok, comment ok, edits denied, student data denied"
api C GET "/assessments/$ASSESS" | py "(d['data']['current_role'], d['data']['permissions']['comment'], d['data']['permissions']['edit_assessment'], d['data']['permissions']['view_student_data'])"
echo -n "C analysis view -> "; code C GET "/ai/assessments/$ASSESS/analysis"; echo
api C POST "/courses/$COURSE/comments" "{\"commentable_type\":\"question\",\"commentable_id\":$Q1,\"body\":\"Consider asking for a worked example.\"}" | py "d['data']['status']"
echo -n "C edit assessment -> "; code C PUT "/assessments/$ASSESS" '{"title":"Hacked","type":"midterm","total_marks":30,"status":"draft"}'; echo
echo -n "C generate rubric -> "; code C POST "/questions/$Q1/rubrics/generate"; echo
echo -n "C submissions -> "; code C GET "/assessments/$ASSESS/submissions"; echo
echo -n "C upload doc -> "; code C POST "/documents"; echo

echo "== Isolation: A/B cannot access C's course; C's own course unaffected"
echo -n "A -> courseC "; code A GET "/courses/$COURSE_C"; echo; echo -n "B -> courseC collaboration "; code B GET "/courses/$COURSE_C/collaboration"; echo
echo -n "B -> courseC comments "; code B GET "/courses/$COURSE_C/comments"; echo
api C GET /courses | py "[(c['course_code'], c['current_role']) for c in d['data']]"

echo "== Owner protection + role change + remove"
echo -n "B removes owner -> "; code B DELETE "/courses/$COURSE/collaborators/$(api A GET /auth/user | py "d['user']['id'] if 'user' in d else d['id']")"; echo
echo -n "A removes self -> "; code A DELETE "/courses/$COURSE/collaborators/$(api A GET /auth/user | py "d['user']['id'] if 'user' in d else d['id']")"; echo
CID=$(api C GET /auth/user | py "d['user']['id'] if 'user' in d else d['id']")
api A PATCH "/courses/$COURSE/collaborators/$CID/role" '{"role":"VIEWER"}' | py "(d['data']['role'], d['data']['status'])"
echo -n "C (now VIEWER) comments -> "; code C POST "/courses/$COURSE/comments" "{\"commentable_type\":\"course\",\"commentable_id\":$COURSE,\"body\":\"x\"}"; echo
api A DELETE "/courses/$COURSE/collaborators/$CID" | py "d['message']"
echo -n "C after removal -> "; code C GET "/courses/$COURSE"; echo
docker compose exec -T mysql mysql -uroot -proot123 facultylens -e "SELECT user_id, role, status FROM course_collaborators WHERE course_id=$COURSE; SELECT COUNT(*) comments_kept FROM collaboration_comments WHERE course_id=$COURSE;" 2>/dev/null

echo "== Overview, activity, summary"
api A GET "/courses/$COURSE/collaboration" | py "(d['data']['current_user']['role'], [(c['user']['name'], c['role'], c['status']) for c in d['data']['collaborators']], len(d['data']['pending_invitations']))"
api A GET "/courses/$COURSE/collaboration/activity?per_page=5" | py "(d['meta']['total'], [a['summary'] for a in d['data']])"
api B GET /collaboration/summary | py "(d['data']['shared_courses_count'], d['data']['unresolved_discussions_count'], d['data']['unread_notifications_count'])"
docker compose exec -T mysql mysql -uroot -proot123 facultylens -e "SELECT action, COUNT(*) FROM audit_logs WHERE course_id=$COURSE AND (action LIKE 'COLLAB%' OR action LIKE 'COMMENT%') GROUP BY action;" 2>/dev/null
echo "== DONE"
