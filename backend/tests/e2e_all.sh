#!/usr/bin/env bash
# STEP 40: run every end-to-end journey against the running Docker stack and summarise pass/fail.
# Each script registers its own faculty account and exercises real API + AI service calls.
#
#   bash backend/tests/e2e_all.sh            # all journeys
#   bash backend/tests/e2e_all.sh reports    # only scripts whose name contains "reports"
set -uo pipefail
cd "$(dirname "$0")/../.."
FILTER="${1:-}"
LOG_DIR="${E2E_LOG_DIR:-backend/storage/logs/e2e}"
mkdir -p "$LOG_DIR"

# Order follows the academic chain: auth/courses → documents → assessments/blueprint/versions → analysis → rubric → grading → performance/CO-PO → chat/generation → collaboration → evaluation → analytics → reports
SCRIPTS=(
  e2e_cors_origin.sh
  e2e_login_validation.sh
  e2e_submissions.sh
  e2e_assessment_blueprint.sh
  e2e_assessment_versioning.sh
  e2e_alignment.sh
  e2e_rubric.sh
  e2e_grading.sh
  e2e_performance_copo.sh
  e2e_academic_chat.sh
  e2e_question_generation.sh
  e2e_collaboration.sh
  e2e_ai_evaluation.sh
  e2e_academic_analytics.sh
  e2e_institutional_reports.sh
)

pass=0; fail=0; results=()
for s in "${SCRIPTS[@]}"; do
  [[ -n "$FILTER" && "$s" != *"$FILTER"* ]] && continue
  [[ -f "backend/tests/$s" ]] || { results+=("SKIP  $s (missing)"); continue; }
  start=$(date +%s)
  if bash "backend/tests/$s" > "$LOG_DIR/${s%.sh}.log" 2>&1; then
    status=PASS; pass=$((pass+1))
  else
    status=FAIL; fail=$((fail+1))
  fi
  results+=("$status  $s  ($(( $(date +%s) - start ))s)")
  echo "${results[-1]}"
done

echo
echo "== E2E summary: $pass passed, $fail failed (logs in $LOG_DIR)"
printf '%s\n' "${results[@]}"
[[ $fail -eq 0 ]]
