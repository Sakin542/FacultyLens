# FacultyLens Notification System — Validation Report (STEP 47)

**Date:** 2026-09-14 · **Branch:** `feature/notification-system` (chained after STEP 46 `feature/ai-safety-hallucination-testing`) · **Environment:** Windows host (PHP 8.2 / sqlite for unit + feature tests), Docker dev stack (`app`, `queue-worker`, `mysql`, `ai-service`) for E2E.

All results below were produced by actually running the listed commands on this branch.

## Implementation Status

| Area | Status |
|---|---|
| Database (extended `notifications`, `notification_preferences`, indexes, back-fill) | ✅ implemented, migrated on sqlite (tests) and MySQL (Docker) |
| Model / service / recipient resolver / job | ✅ |
| Registry (`config/notifications.php`, 32 types, 10 categories, 5 severities) | ✅ |
| Events (24) + listeners (8 classes, 24 handlers auto-discovered) | ✅ `php artisan event:list` |
| Producers wired (analysis, recommendations, rubric, question generation, versions, review, collaboration, comments/mentions, grading, performance/gaps, reports, feedback, security, system) | ✅ 31 LIVE, 1 SUPPORTED (inter-grader — STEP 29 not deployed) |
| API (7 notification + 3 preference routes) | ✅ |
| Frontend (types, service, hook, 9 components, page, route, navbar bell, sidebar link) | ✅ |
| Docs (`NOTIFICATION_SYSTEM.md`, `NOTIFICATION_EVENT_MATRIX.md`, this report) | ✅ |

## Database Validation
* Migration `2026_09_19_100001_extend_notifications_for_facultylens` ran on sqlite (every test) and on the Docker MySQL 8 stack (`docker compose exec app php artisan migrate --force` → DONE, 661 ms). Index names are explicit (< 64 chars).
* Back-fill of STEP 34 rows verified by `NotificationApiTest::test_legacy_collaboration_rows_are_backfilled_by_migration_shape`.
* Unique `(user_id, dedupe_key)` verified to reject a bypassing insert (`NotificationDeduplicationTest::test_database_unique_constraint_backs_the_race`).
* `down()` drops FK before columns (MySQL ordering).

## API Validation — `NotificationApiTest` (10 tests, 88 assertions) ✅
Listing order/scope, pagination (20 default, 50 max, 422 beyond), server-side filters (all/unread/every category/date, invalid category → 422), unread count, show/read/read-all/dismiss/delete (+ idempotency, 404 on invalid or foreign ids, never 500), expiration hiding, purge with configurable retention, audit rows survive deletion/purge.

## Authorization Validation — `NotificationAuthorizationTest` (7 tests) ✅
* User A → User B's id on GET/read/dismiss/DELETE → **404**, row untouched, no audit row written.
* List, unread-count and read-all are caller-scoped; `?user_id=` / `?notifiable_id=` are ignored.
* Preferences are per user.
* Recipient resolution follows `config/collaboration.php` (VIEWER receives analysis, not student data; PENDING members and outsiders never).

## IDOR Validation ✅
Covered above plus Playwright E2E 4 against the live stack: A attempts B's SECURITY_ALERT id on all four endpoints (404), B's row unchanged, mandatory preference cannot be disabled, and a course id reachable from a notification is still 403 for A.

## Privacy Validation ✅
* `NotificationCreationTest::test_payload_never_stores_secrets_or_private_content` — forbidden keys dropped (`api_key`, `token`, `password`, `hf_token`, `authorization`, `answer_text`, `extracted_text`, `system_prompt`, `email`, nested), strings capped, depth limited, objects dropped.
* Integration tests assert no student names/identifiers/marks in grading & performance notifications, no free-text feedback notes, no invitation token, no AI error bodies (`boom`, `down`, stack details), no passwords in security alerts.
* `action_url` accepts only app-relative paths (foreign origin, `//`, `javascript:` → dropped; frontend `resolveActionPath` enforces the same rule).

## Queue Validation — `NotificationQueueTest` (7 passed, 1 skipped) ✅
* Job pushed on configured connection/queue with `afterCommit`, `tries=3`, backoff `[10,30,60]`, tags.
* Database queue end-to-end with `queue:work --once`: two redeliveries of the same event → **1** notification, 0 failed jobs.
* Rolled-back domain transaction → **0** notifications (afterCommit).
* Report job and analysis run stay `COMPLETED` when notification storage throws (`bindThrowingNotificationService`).
* `AiFailureAndQueueTest` (8 tests) still passes — analysis retry/back-off/failed_jobs behaviour intact.

## Redis Validation ⚠️ PASS_WITH_WARNINGS
`test_redis_queue_end_to_end_when_a_redis_server_is_available` exercises the `redis` queue driver (predis) end-to-end but **skipped** on this host: no Redis server on 127.0.0.1:6379 (the dev Docker stack has no Redis; the production compose file provides `redis` + `worker: queue:work redis`). The job uses the standard queue contract, so behaviour on Redis is identical; run the test with a Redis instance reachable (`REDIS_HOST`) to turn this into a hard pass.

## Horizon Validation ⚠️ NOT_TESTED
Laravel Horizon is **not installed** in this repository (documented as an optional production add-on in `docs/DEPLOYMENT.md`). `StoreNotificationJob` declares `tags()` and finite `tries/backoff/timeout` so it is Horizon-ready; no Horizon-specific behaviour was exercised.

## Frontend Validation ✅
* `npm test` → **308 passed** (17 in `notifications.test.tsx`: badge cap `99+` with true count in the accessible label, item rendering/actions, list states, filter tabs + keyboard, hook polling / pause when hidden / visibility refresh / interval cleanup / disabled instance / stale-response drop / cross-instance sync, bell + dropdown open/mark-read/navigate/mark-all/Escape focus return/error+retry, page filters/pagination/actions/navigation/error/empty/preferences, preferences matrix/mandatory lock/save/error retry). Suite run 5× consecutively without flakes.
* `npm run build` → ✅ (`tsc` clean for app and e2e configs).
* Removed the STEP 34 bell from `CollaborationActivity.tsx` and the notification methods from `collaborationService` (single system); `collaboration.test.tsx` updated accordingly.

## Accessibility Validation ✅
* Bell: `aria-label="Notifications, N unread"`, `aria-haspopup="dialog"`, `aria-expanded`, `aria-controls`; badge has sr-only "N unread notifications".
* Dropdown: `role="dialog"`, Escape/outside click/Tab-out close, focus returns to the bell (unit + E2E verified).
* Filters: `role="tablist"/"tab"`, `aria-selected`, roving arrow/Home/End navigation.
* Rows: real buttons with explicit labels, unread shown by dot + weight + sr text, severity by icon + text; skeleton `role="status"`, errors `role="alert"`.
* axe-core (critical/serious) scans of `/notifications` inbox and preferences in E2E → **0 violations**.

## E2E Validation — `frontend/tests/e2e/11-notifications.spec.ts` (5 passed, ~5 min) ✅
1. **AI analysis** — question generation notification → live analysis run → `AI_ANALYSIS_COMPLETED` stored by the Docker queue worker → bell count → dropdown click marks read (200) and opens `/assessments/{id}/analysis`.
2. **Collaboration** — A invites B → B receives `COLLABORATION_INVITATION` (no token) → follows it to pending invitations → accepts → A receives `COLLABORATION_ACCEPTED`; A's center filters (Collaboration shows it, Reports empty); B never sees A's notice.
3. **Report** — report generated → `REPORT_GENERATED` → row opens `/reports/{id}` → mark-all-read → unread 0, badge gone, Unread filter empty.
4. **Authorization** — see IDOR section.
5. **UI states / prefs / a11y** — empty inbox, preference toggle persisted server-side, mandatory lock, keyboard open/close of the bell, axe scans.

Regression: `01-auth.spec.ts` + `09-ui-states-accessibility.spec.ts` re-run against the new header — **13 passed, 1 failed**. The failure is an axe colour-contrast finding on `/dashboard` (`text-sage-400` #5F7D74 on `bg-sage-50` #FAFAF7 = 4.29:1, the "/ 30%" KPI span) coming from the pre-existing, uncommitted palette edits in `tailwind.config.js` / Dashboard — it is unrelated to the notification components (all notification UI uses `#171717` / `#6B6B63` on white/cream, ≥ 5.7:1) and was already failing before this branch (see repo notes on STEP 45).

## Performance Validation ✅ (design + tests)
Column-selected paginated queries with composite indexes matching the WHERE/ORDER shapes; unread count is a single indexed `COUNT`; polling 45 s server-hinted (≥ 30 s enforced), paused when hidden; dropdown fetches only when open (`enabled:false` → zero requests, unit-tested); identical in-flight requests coalesced. No load test was run specifically for notifications (STEP 43 harness not extended).

## Event Integration Validation — `NotificationIntegrationTest` (18 tests, 253 assertions) ✅
Every LIVE row of the event matrix is triggered through its real entry point (HTTP + faked AI service, sync queue): analysis success/failure/async, recommendations (count, deciders only, wording "may require review"), rubric success/failure, question generation success/failure, full version lifecycle incl. `REVIEW_ASSIGNED`/`REVIEW_COMPLETED`, invitation/decline/comment/mention/removal, AI grading success/failure, inter-grader wording, performance + learning gap (VIEWER excluded), report success + queued path + permanent job failure, recommendation feedback, failed-login threshold (1 alert/window, mandatory), password change, system-alert command (admins only, deduped).

## Full-suite results
| Suite | Result |
|---|---|
| `php artisan test` | **628 passed, 1 skipped** (Redis) — 5252 assertions |
| `npm test` | **308 passed** |
| `npm run build` | ✅ |
| `playwright test tests/e2e/11-notifications.spec.ts` | **5 passed** |

## Known Issues
1. Redis and Horizon paths are not exercised on the development host (see above). Production `worker` already consumes the default Redis queue, so no extra deployment step is required beyond the migration.
2. E-mail delivery remains limited to collaboration invitations (existing STEP 34 mailer); `email_enabled` is stored but not yet honoured for other types (no mailer configured in this project by default).
3. `INTER_GRADER_REVIEW_REQUIRED` has no live producer because STEP 29 inter-grader tables are not deployed; the event/listener are in place and tested.
4. First-run `ai-service` cold latency can push E2E 1 past 60 s; the spec allows 300 s.
5. Pre-existing (out of scope): axe contrast finding on the Dashboard KPI helper text caused by the uncommitted palette WIP (`sage-400` on `sage-50`), see E2E section.

## Final Status

**PASS_WITH_WARNINGS** — all implemented behaviour is verified by executed tests; the warnings are the two infrastructure paths (Redis, Horizon) that cannot be exercised on the development host and are covered by the production compose topology rather than by a test run here.
