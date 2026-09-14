# FacultyLens Email System

The e-mail system is the second delivery channel of the notification system (STEP 47). Every FacultyLens event that
produces an in-app notification can also produce an e-mail, decided per recipient and per notification type, and
delivered asynchronously through Redis, Laravel Horizon and Gmail SMTP. Authentication e-mails (password reset) use
the same pipeline but bypass notification preferences.

```
FacultyLens Event
      ↓
Laravel Event / Listener (app/Listeners/Notifications/*)
      ↓
NotificationService::notify()                  ← ONLY entry point for notifications
      ├──► in-app preference ──► StoreNotificationJob ──► notifications table ──► Notification Center
      └──► EmailService::queueNotificationEmail(payload)
                 ├─ e-mail preference / mandatory category / never-email list
                 ├─ recipient validation, idempotency key, storm limits
                 ├─ email_deliveries row (PENDING)
                 └─ SendFacultyLensEmailJob  ──► queue "emails" ──► Redis ──► Horizon (supervisor-emails)
                                                    ↓
                                          FacultyLensMail (Blade HTML + derived plain text)
                                                    ↓
                                          Gmail SMTP (smtp.gmail.com:587, STARTTLS)
                                                    ↓
                                          email_deliveries.status = SENT | FAILED
```

The two channels are independent: an e-mail failure never affects the in-app row, and neither channel can fail or
roll back the academic operation that raised the event (listeners run inside `guard()`, `EmailService` never throws
to a producer).

## Components

| Layer | Location | Responsibility |
|---|---|---|
| Config | `backend/config/email.php` | Enabled switch, queue, preference defaults, mandatory categories, storm limits, template + subject registry, forbidden context keys |
| Config | `backend/config/mail.php` | Transports (`smtp` = Gmail, `mailpit` = local capture, `log`, `array`) — values come from `.env` only |
| Config | `backend/config/horizon.php` | `supervisor-default` (AI/report jobs) and `supervisor-emails` (queue `emails`) |
| Service | `App\Services\Email\EmailService` | `shouldEmail()`, `queueNotificationEmail()`, `sendToUser()`, `sendPasswordReset()`, `sendTestEmail()`, `send()` |
| Service | `App\Services\Email\EmailContentResolver` | type → template/subject, template context (resolves assessment/course names from ids, strips forbidden keys, builds absolute links from `FRONTEND_URL`), preview fixtures |
| Service | `App\Services\Email\EmailErrorClassifier` | Throwable → stable error code + temporary/permanent decision |
| Service | `App\Services\Email\EmailLogSanitizer` | Masks credentials, AUTH lines, DSNs and `token=` query values before anything is logged or stored |
| Service | `App\Services\Email\EmailPlainText` | HTML → readable plain-text alternative |
| Mailable | `App\Mail\FacultyLensMail` | The single Mailable: `template`, `subjectLine`, `context`; adds `X-FacultyLens-Type` / `X-FacultyLens-Delivery` headers |
| Job | `App\Jobs\SendFacultyLensEmailJob` | Sends one delivery; idempotent; retries temporary errors (backoff 30/120/600 s, 4 tries), fails permanent errors immediately |
| Model | `App\Models\EmailDelivery` | `email_deliveries` row (`toApi()` masks the recipient and hides the idempotency key) |
| Model | `App\Models\NotificationPreference` | `emailEnabled()`, `isEmailMandatory()`, `emailDefault()`, `matrixFor()` |
| Templates | `backend/resources/views/emails/**` | Layout, components, one Blade view per e-mail family, `text/plain.blade.php` |
| API | `App\Http\Controllers\Api\EmailController` | `/api/email/status`, `/api/email/test`, `/api/email/deliveries[/{id}]`, `/api/email/preview/{template}` |
| API | `App\Http\Controllers\Api\PasswordResetController` | `/api/auth/forgot-password`, `/api/auth/reset-password` (see `docs/PASSWORD_RESET_FLOW.md`) |
| CLI | `email:check`, `email:test {recipient} [--sync]`, `email:purge-deliveries` | Config check (password masked), pipeline test, retention |
| Frontend | `components/notifications/NotificationPreferences.tsx` | In-app + e-mail matrix, per-category e-mail summary; embedded in `/settings` and `/notifications?view=preferences` |
| Frontend | `pages/ForgotPassword.tsx`, `pages/ResetPassword.tsx`, `services/emailService.ts`, `services/authService.ts` | Recovery pages; coarse status / own delivery log |

## Delivery lifecycle

```
PENDING ──► PROCESSING ──► SENT
                 │
                 ├──► PENDING (temporary error; error_code kept; queue retries with backoff)
                 └──► FAILED  (permanent error, or retries exhausted)
CANCELLED   (storm limit hit at queue time — in-app notification still delivered)
```

`email_deliveries` columns: `user_id`, `notification_id` (linked lazily by the job), `type`, `category`, `template`,
`recipient`, `subject`, `status`, `provider`, `idempotency_key` (unique), `attempts`, `message_id`, `queued_at`,
`sent_at`, `failed_at`, `error_code`, `error_message` (sanitised, ≤500 chars). The rendered body and any credential
are never stored.

### Error codes

| Code | Retry? | Typical cause |
|---|---|---|
| `SMTP_CONNECTION_FAILED` | yes | connection refused / reset |
| `SMTP_TIMEOUT` | yes | Gmail unreachable, egress blocked |
| `DNS_RESOLUTION_FAILED` | yes | container has no DNS |
| `SMTP_TEMPORARY_REJECTION` | yes | 421/450/451/452 |
| `SMTP_RATE_LIMITED` | yes | Gmail “too many”/quota replies |
| `SMTP_AUTHENTICATION_FAILED` | **no** | 535 — wrong App Password, 2-Step Verification off |
| `INVALID_RECIPIENT` | **no** | RFC-invalid address, 550/551/553 |
| `SMTP_PERMANENT_REJECTION` | **no** | other 5xx |
| `TEMPLATE_RENDER_FAILED` | **no** | missing view / undefined variable |
| `MAIL_CONFIGURATION_ERROR` | **no** | unknown mailer |
| `QUEUE_DISPATCH_FAILED` | — | Redis unavailable at queue time |
| `RATE_LIMITED` | — | per-user ceiling (row is CANCELLED) |

## Idempotency and storm protection

* Notification e-mails use `notification:{user_id}:{dedupe_key}` as idempotency key (`dedupe_key` defaults to
  `TYPE:entity_type:entity_id`). Re-emitted events and redelivered jobs collapse onto one row / one message.
  Notifications without an entity get a per-minute bucketed hash.
* Password-reset e-mails key on `sha256(token)` — every request is a distinct, single message.
* Per-user ceilings (`config/email.php → rate_limit`): 30 non-security e-mails/hour, 20 security e-mails/hour,
  and at most 5 of the same type within 20 s. Overflow becomes a `CANCELLED` row with `error_code=RATE_LIMITED`;
  the in-app notification is unaffected.
* A job whose row is already `SENT` / `FAILED` / `CANCELLED` exits without sending.

## Preferences

`notification_preferences.email_enabled` is tri-state: `NULL` = never chosen → category default from
`config('email.default_email_enabled')`, `true`/`false` = explicit. The **SECURITY** category is e-mail-mandatory;
`ASSESSMENT_VERSION_RESTORED` and `COLLABORATION_ROLE_CHANGED` are never e-mailed. Password-reset e-mails are not
notifications and are always sent. All preference changes go through the authenticated
`PUT/PATCH /api/notification-preferences` endpoints (per caller only); there is no public unsubscribe endpoint —
e-mails link to `FRONTEND_URL/settings`.

## Security summary

* Credentials exist only in `backend/.env` (`MAIL_PASSWORD`); `.gitignore` excludes `.env` and `.env.*`.
* `email:check`, `/api/email/status` and the preferences API never return host, port, username or password.
* `EmailLogSanitizer` masks the configured password (raw and base64), `AUTH PLAIN/LOGIN` lines, credential DSNs,
  `password=`/`token=` pairs and reset URLs before any log/audit/database write.
* Recipients are validated with `FILTER_VALIDATE_EMAIL` and rejected on CR/LF/TAB/NUL; subjects are single-line;
  Blade escaping neutralises HTML in titles/messages/names; `action_url` must be an app-relative path or it falls back
  to `/dashboard`; links are built from `FRONTEND_URL`, never from request input.
* Template context drops `password*`, `token`, `api_key`, `secret`, `cookie`, `authorization`, `answer_text`,
  `student_name`, `student_identifier`, `awarded_marks`, … (`config('email.forbidden_context_keys')`).
* `/api/email/test` requires authentication, is off in production unless `EMAIL_TEST_ENDPOINT_ENABLED=true`, is
  throttled (5/h), and faculty may only target their own address (admins any valid address). It accepts no
  transport parameters.
* Horizon dashboard (`/horizon`) is open only in `APP_ENV=local`; elsewhere `viewHorizon` requires an ADMIN.
* E-mails never approve, finalize, grade or change anything — they link to the authenticated application.

## Operations

```bash
php artisan email:check                       # effective config, password masked
php artisan email:test you@example.com        # queue one test (add --sync to send inline)
php artisan horizon                            # run workers (docker: service `horizon`)
php artisan horizon:status | horizon:terminate # restart after deploy/code change
php artisan queue:failed | queue:retry {id}    # failed_jobs (email failures also live in email_deliveries)
php artisan email:purge-deliveries             # retention (scheduled daily 03:30)
```

Dashboard: `GET /api/email/deliveries?status=FAILED` (own rows) or the Horizon UI at `/horizon`.

See also: `EMAIL_CONFIGURATION.md`, `EMAIL_EVENT_MATRIX.md`, `EMAIL_VALIDATION_REPORT.md`, `PASSWORD_RESET_FLOW.md`,
`NOTIFICATION_SYSTEM.md`.
