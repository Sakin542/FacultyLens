# FacultyLens Notification System (STEP 47)

> A notification is **informational only**. It never approves, finalizes, grades, ranks or decides anything.
> `AI/System event → notification → faculty review → faculty decision`.

## 1. Architecture

```text
FacultyLens domain event (analysis completed, version finalized, report ready, …)
        │  event(new App\Events\…)            ← fired after the domain state is committed
        ▼
App\Listeners\Notifications\*Listener       ← resolves recipients from the authorization model, builds wording
        │  NotificationService::notify()      ← validates type/category/severity, sanitises payload, checks preferences,
        │                                       derives the deduplication key
        ▼
App\Jobs\StoreNotificationJob (queued)      ← afterCommit(); tries=3, backoff 10/30/60 s, timeout 30 s
        │  queue.default connection           ← database queue (dev/test) · Redis (production `worker` service, Horizon-compatible)
        ▼
NotificationService::store()                ← idempotent insert; unique(user_id, dedupe_key) makes duplicates impossible
        ▼
notifications table (source of truth)       ← + audit_logs row NOTIFICATION_CREATED
        ▼
GET /api/notifications, /unread-count …     ← owner-scoped, paginated, server-side filtered
        ▼
frontend/src/hooks/useNotifications.ts      ← polling (45 s, paused when the tab is hidden), in-flight de-duplication,
        │                                       cross-instance sync
        ▼
NotificationBell → NotificationDropdown → /notifications (Notification center) → faculty action
```

Broadcasting is **not** configured in this project, so the frontend polls (`poll_interval_seconds` is returned by the API, default 45 s, minimum enforced client-side 30 s). The database is always the source of truth; the unread count shown in the bell is the backend's count, never a client-side tally.

### Key files

| Layer | Path |
|---|---|
| Registry (types, categories, severities, retention, queue) | `backend/config/notifications.php` |
| Constants | `backend/app/Notifications/NotificationType.php`, `NotificationCategory.php`, `NotificationSeverity.php` |
| Model | `backend/app/Models/Notification.php` (extends Laravel `DatabaseNotification`), `NotificationPreference.php` |
| Service | `backend/app/Services/Notification/NotificationService.php`, `NotificationRecipientResolver.php` |
| Job | `backend/app/Jobs/StoreNotificationJob.php` |
| Events | `backend/app/Events/*.php` (24 domain events, base `DomainEvent`) |
| Listeners | `backend/app/Listeners/Notifications/*Listener.php` (auto-discovered — `php artisan event:list`) |
| API | `backend/app/Http/Controllers/Api/NotificationController.php`, `NotificationPreferenceController.php`, routes in `routes/api.php` |
| Console | `notifications:purge` (scheduled 03:00), `notifications:system-alert` (`routes/console.php`) |
| Migration | `database/migrations/2026_09_19_100001_extend_notifications_for_facultylens.php` |
| Frontend | `frontend/src/types/notification.ts`, `services/notificationService.ts`, `hooks/useNotifications.ts`, `components/notifications/*`, `pages/Notifications.tsx` |

## 2. Notification types, categories, severities

Categories: `AI · ASSESSMENT · COLLABORATION · REVIEW · GRADING · PERFORMANCE · REPORT · FEEDBACK · SECURITY · SYSTEM`.
Severities: `INFO · SUCCESS · WARNING · ERROR · CRITICAL` — severity is delivery urgency, **never** an academic judgement.
`SECURITY` and `SYSTEM` are mandatory (cannot be muted).

The full type list with category, default severity, recipients and wording is in [NOTIFICATION_EVENT_MATRIX.md](NOTIFICATION_EVENT_MATRIX.md). Frontend enums in `frontend/src/types/notification.ts` mirror the config; `NotificationCreationTest::test_registry_is_consistent` guards the backend side.

## 3. Database

`notifications` (Laravel's native table, extended — STEP 34 rows were back-filled by the migration):

| column | notes |
|---|---|
| `id` uuid PK | |
| `user_id` FK → users (cascade) | recipient; `notifiable_*` kept for Laravel compatibility |
| `type` | FacultyLens type constant (e.g. `AI_ANALYSIS_COMPLETED`) |
| `category`, `severity` | validated against the registry |
| `title`, `message` | plain text, ≤255 / ≤2000 chars |
| `data` json | identifiers only (`assessment_id`, `analysis_id`, `report_id`, `action_label`, …) |
| `action_url` | app-relative path; foreign origins are rejected |
| `entity_type`, `entity_id` | for deduplication and audit |
| `dedupe_key` | `unique(user_id, dedupe_key)` |
| `read_at`, `dismissed_at`, `expires_at`, `created_at`, `updated_at` | |

Indexes: `(user_id, dismissed_at, created_at)`, `(user_id, read_at, dismissed_at)`, `(user_id, category, created_at)`, `(user_id, type)`, `expires_at`, unique `(user_id, dedupe_key)`.

`notification_preferences`: `id, user_id FK, notification_type, in_app_enabled, email_enabled (nullable), timestamps`, unique `(user_id, notification_type)`. No row = in-app enabled; `email_enabled` NULL = category default from `config/email.php` (see `docs/EMAIL_SYSTEM.md`).

## 4. API

All routes are `auth:sanctum`; every lookup is scoped to the caller inside `NotificationService` (another user's id → **404**, never the row).

| Method | Route | Notes |
|---|---|---|
| GET | `/api/notifications?filter=all\|unread\|<CATEGORY>&page=&per_page=[&from=&to=]` | default 20/page, max 50; `meta.unread_count`, `meta.poll_interval_seconds` |
| GET | `/api/notifications/unread-count` | `{unread_count, poll_interval_seconds}` |
| GET | `/api/notifications/{uuid}` | audit `NOTIFICATION_VIEWED` |
| POST | `/api/notifications/{uuid}/read` | idempotent; audit `NOTIFICATION_READ` |
| POST | `/api/notifications/read-all` | |
| POST | `/api/notifications/{uuid}/dismiss` | hides from lists; also marks read |
| DELETE | `/api/notifications/{uuid}` | audit rows are kept |
| GET | `/api/notification-preferences` | full matrix + `mandatory_categories` + `email_available` |
| PUT | `/api/notification-preferences` | `{preferences:[{notification_type,in_app_enabled,email_enabled?}]}` |
| PATCH | `/api/notification-preferences/{type}` | single type |

Responses follow the project envelope `{status, message, data, meta}`; notification rows are serialised by `Notification::toApi()` (no `dedupe_key`, no `notifiable_*`).

## 5. Preferences

Faculty can switch off any non-mandatory type in-app and per e-mail (grouped by category on `/settings` and `/notifications?view=preferences`). Mandatory types are stored/returned as enabled regardless of input. `email_enabled` drives the e-mail channel (`EmailService`, `docs/EMAIL_SYSTEM.md`): SECURITY is e-mail-mandatory, a few low-value types are never e-mailed, and `email_available` tells the UI whether a real mailer is configured. Collaboration **invitations** to people without an account still use the STEP 34 `CollaborationNotification` mail.

## 6. Authorization & privacy

* Recipients come from `NotificationRecipientResolver` — course owner + **active** collaborators whose role holds the ability in `config/collaboration.php` (`view_analysis`, `run_analysis`, `approve_recommendation`, `edit_assessment`, `view_student_data`, …). Pending/revoked members and outsiders are never recipients; request input never chooses recipients.
* A notification grants **no access**: its `action_url` opens a page that re-authorizes against the underlying policy (tested in `NotificationAuthorizationTest::test_a_notification_does_not_grant_access_to_its_target`).
* Payload sanitisation (`NotificationService::sanitizeData`): forbidden keys (`token`, `api_key`, `password`, `answer_text`, `extracted_text`, `system_prompt`, `email`, …) are dropped, strings capped at 500 chars, depth 2, max 25 keys. Student names/identifiers/marks and free-text feedback notes are never included by any listener. Invitation tokens are never stored in-app.
* Audit events: `NOTIFICATION_CREATED · VIEWED · READ · DISMISSED · DELETED · PREFERENCE_UPDATED` (through `AuditLogService`, which redacts sensitive keys).

## 7. Queue / Redis / Horizon

* `StoreNotificationJob` is dispatched `afterCommit()` on `config('notifications.queue.connection') ?: queue.default` — Redis in dev and production, consumed by **Laravel Horizon** (`horizon` service; `config/horizon.php` supervisors `supervisor-default` for `default` and `supervisor-emails` for `emails`). The job exposes `tags()` so it groups cleanly in the Horizon UI. E-mails ride the separate `emails` queue (`SendFacultyLensEmailJob`).
* Failure isolation: the domain operation is already committed before the job is queued; listeners are wrapped in `guard()`; `notify()` swallows dispatch errors. `NotificationQueueTest` proves a report/analysis stays `COMPLETED` when notification storage throws.
* Retries never duplicate: `store()` checks `(user_id, dedupe_key)` and the unique index backs the race. `notifications.queue.enabled=false` writes inline (useful for tests that count `jobs` rows).
* Env: `NOTIFICATIONS_QUEUE_ENABLED`, `NOTIFICATIONS_QUEUE_CONNECTION`, `NOTIFICATIONS_QUEUE`, `NOTIFICATIONS_QUEUE_TRIES`, `NOTIFICATIONS_POLL_INTERVAL`, `NOTIFICATIONS_FAILED_LOGIN_THRESHOLD`, `NOTIFICATIONS_FAILED_LOGIN_WINDOW`, `NOTIFICATIONS_READ_RETENTION_DAYS`, `NOTIFICATIONS_MAX_RETENTION_DAYS`.

## 8. Deduplication

`dedupe_key` defaults to `TYPE:entity_type:entity_id`; producers override it where the same entity legitimately recurs (failures use a minute bucket, security alerts an hour bucket, review assignment the submission timestamp). Title/message text is never used for deduplication.

## 9. Expiration & retention

`expires_at` hides a row from lists and counts (invitations default to 7 days, report notifications inherit the report's expiry). `notifications:purge` (daily 03:00) deletes rows expired > 1 day, read/dismissed rows older than `read_retention_days` (90) and anything older than `max_retention_days` (365); `0` disables a rule. Audit rows are never deleted.

## 10. Frontend

* `useNotifications()` — list + unread count + `refresh/markAsRead/markAllAsRead/dismiss/remove`; optimistic updates confirmed by the server's `meta.unread_count`; polling paused while `document.hidden`; `enabled:false` makes an instance dormant (closed dropdown issues no requests); identical in-flight requests are coalesced and stale responses dropped.
* `NotificationBell` (count from `/unread-count`, `99+` cap with the true count in the accessible label), `NotificationDropdown` (`role=dialog`, Escape/outside-click/Tab-out close, focus returns to the bell), `NotificationItem` (icon, title, message, relative time, category chip, severity chip with icon + label, unread dot + sr-only text), `NotificationList` (skeleton / error + Retry / empty), `NotificationFilters` (tablist with arrow-key roving), `NotificationPreferences`, `pages/Notifications.tsx` (`/notifications`, `?filter=&page=&view=preferences`).
* Palette: white `#FFFFFF`, cream `#F7F4EE`, text `#171717`, secondary `#6B6B63`, border `#E7E2D8`; no colour-only meaning.

## 11. Testing

| Suite | Command |
|---|---|
| Laravel (`tests/Feature/Notifications/*`, 66 tests) | `cd backend && php artisan test tests/Feature/Notifications` |
| Full Laravel suite | `php artisan test` |
| Frontend units (`src/tests/components/notifications.test.tsx`) | `cd frontend && npm test` |
| Playwright (`tests/e2e/11-notifications.spec.ts`) | `npm run test:e2e -- tests/e2e/11-notifications.spec.ts` (needs the Docker stack) |

## 12. Troubleshooting

| Symptom | Check |
|---|---|
| Notification never appears | `docker compose logs horizon` / `/horizon`; `failed_jobs`; user preference for the type (`notification_preferences`); recipient role in `course_collaborators` (must be `ACTIVE`) |
| Duplicate notification | should be impossible — inspect `dedupe_key` of both rows; a `NULL` key means the producer passed none and no entity |
| Bell count differs from list | count excludes dismissed/expired rows; force `GET /api/notifications/unread-count` |
| 404 on a valid-looking id | ids are uuids and owner-scoped; another user's id is deliberately indistinguishable from a missing one |
| Listener not firing | `php artisan event:list` must show the `App\Listeners\Notifications\*` handlers; run `php artisan event:clear` if events were cached |
| Legacy STEP 34 rows look odd | they were back-filled (`type` mapped from `data.event`); re-run the migration's `backfillCollaborationRows()` logic via `php artisan tinker` if rows were imported afterwards |
