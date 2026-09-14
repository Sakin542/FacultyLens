# FacultyLens Email System — Validation Report

**Date:** 2026-09-14 · **Branch:** `feature/email-system` · **Environment:** Docker dev stack (`app`, `horizon`,
`redis`, `mailpit`, `mysql`, `ai-service`), host PHP 8.2 for the test suite, Node/Vite for the frontend.

## 1. Scope

End-to-end e-mail delivery for FacultyLens notifications and authentication:

```
FacultyLens event → NotificationService → e-mail preference → EmailService → email_deliveries (PENDING)
  → SendFacultyLensEmailJob (queue "emails") → Redis → Horizon → FacultyLensMail → Gmail SMTP → SENT
```

plus the password-reset workflow (Laravel password broker → queued reset e-mail → `/reset-password` → new password).

## 2. Automated tests

| Suite | Command | Result |
|---|---|---|
| Backend — whole suite | `php artisan test` | **776 passed**, 2 skipped (live AI / live SMTP opt-in), 0 failed |
| Backend — `tests/Feature/Email/*` (7 files) | `php artisan test tests/Feature/Email` | 59 passed, 1 skipped (`EMAIL_LIVE_TESTING` opt-in) |
| Backend — `tests/Feature/Auth/PasswordResetTest` | | 15 passed |
| Frontend — Vitest | `npm run test` | **352 passed** (28 files) incl. `passwordReset.test.tsx` (12) and the extended `notifications.test.tsx` |
| Frontend — TypeScript | `tsc --noEmit` (app + e2e configs) | clean |
| Frontend — build | `npm run build` | OK; `dist/` contains no `MAIL_*`, `smtp.gmail` or sender address |
| E2E — Playwright | `npx playwright test tests/e2e/13-email-password-reset.spec.ts` | **4 passed** (3.3 min) against Docker :8080 with `MAIL_MAILER=mailpit` |

### Email suites — what they prove

| File | Coverage |
|---|---|
| `EmailConfigurationTest` | env-driven config, `email:check` masks the password, every template renders HTML + plain text with the brand layout (header tagline, footer sentence, cream/charcoal palette, no web fonts/scripts), subjects, From/tracing headers, coarse `/api/email/status`, preview endpoint (never sends, path-traversal safe), global disable keeps in-app |
| `EmailNotificationTest` | notify() → in-app row **and** templated e-mail linked by `notification_id`; e-mail off ⇒ in-app only; in-app off ⇒ e-mail only; every registered type maps to an existing template; real domain events (`AssessmentAnalysisCompleted/Failed`, `ReportGenerated`) e-mail the right recipient with resolved assessment/course names; failed analysis never uses the success template and never leaks the technical error; template failure ⇒ `FAILED` row, caller unaffected |
| `EmailPreferenceTest` | NULL ⇒ category default, explicit false suppresses e-mail only, SECURITY always e-mailed, never-email types suppressed, API persists/reports the matrix, API cannot disable mandatory security e-mail, PATCH single type, password reset ignores preferences |
| `EmailDeduplicationTest` | same event ×3 ⇒ 1 e-mail; per-recipient/per-entity keys; redelivered job never re-sends; CANCELLED never sent; hourly ceiling ⇒ CANCELLED overflow, in-app kept; burst cap per type; security/auth e-mails not throttled by the regular ceiling; entity-less notifications bucketed |
| `EmailQueueTest` | job pushed on `emails` queue, `afterCommit`, tries/backoff/tags; database driver end to end with `queue:work`; temporary transport errors re-thrown for retry with row `PENDING` + error code; permanent errors (`RfcCompliance`, 535 auth) fail once; auth error message sanitised (raw + base64); retries exhausted ⇒ `FAILED`; `failed()` callback; rolled-back domain transaction leaves no delivery; e-mail failure isolated from the notification |
| `EmailAuthorizationTest` | all endpoints 401 anonymous; faculty test only to own address (403 otherwise), admin any valid address (422 invalid / header injection); test endpoint disabled flag + 429 throttle; transport parameters in the request are ignored and never echoed; delivery log scoped per user (404 cross-user); user A cannot alter user B's preferences; preview gated |
| `EmailSecurityTest` | SMTP password absent from rendered mail, API bodies, `email_deliveries`, `audit_logs`; sanitizer masks credentials/AUTH/DSN/`token=`; failure logs contain no secret; recipient header injection rejected; subject CR/LF and HTML/`javascript:` injection neutralised; student data & secrets stripped from template context; only `reset_url` survives into the job payload; plain-text conversion; API masks recipient and hides internal key/error text |
| `Auth/PasswordResetTest` | see `PASSWORD_RESET_FLOW.md` — hashed token, queued job, URL/recipient/subject, identical responses for known/unknown/throttled, per-IP rate limit, configured frontend URL only, valid/invalid/expired/reused/wrong-e-mail tokens, policy + confirmation, secure hashing, old password rejected / new accepted, API tokens revoked + security alert, no token/password in logs/audit/responses, preferences ignored |

## 3. Docker / queue verification

| Check | Result |
|---|---|
| `docker compose exec app php artisan migrate --force` | `create_email_deliveries_table`, `make_notification_preference_email_nullable` applied |
| `email:check` inside the `app` container | `MAIL_MAILER=smtp`, `smtp.gmail.com:587 tls`, `MAIL_PASSWORD ********`, `QUEUE_CONNECTION=redis`, queue `emails` |
| DNS/egress from the Laravel container | `fsockopen smtp.gmail.com:587` → `220 smtp.gmail.com ESMTP … gsmtp` |
| Horizon | `horizon:status` running; supervisors `supervisor-default` (queue `default`) and `supervisor-emails` (queue `emails`); Redis keys `facultylens_horizon:*` present |
| Mailpit capture run | `email:test` → delivery `PENDING` → Horizon `SendFacultyLensEmailJob … DONE` → `SENT` in ~5 s; message visible in Mailpit with subject `FacultyLens — Test Email` |
| Playwright with capture | forgot → reset e-mail captured → link → new password → reuse rejected → old password rejected → new login OK → security-alert e-mail captured; test e-mail `PENDING→SENT` via `/api/email/deliveries/{id}`; Settings e-mail preferences persist |

## 4. Controlled live Gmail test (manual, one-off)

Configuration: `backend/.env` with `MAIL_MAILER=smtp`, Gmail App Password entered locally, `docker compose restart horizon`.

| Step | Evidence |
|---|---|
| `php artisan email:test saomiorashid542@gmail.com` | delivery #6 `PENDING` → Horizon `RUNNING … 5s DONE` → **`SENT`**, `attempts=1`, `provider=smtp`, `error_code=null` |
| Gmail accepted the message; recipient inbox | Subject `FacultyLens — Test Email`, HTML renders (header/tagline/footer), plain-text alternative present, no password in the body (user-confirmed) |
| Forgot password from the UI (account `…@aust.edu`, user 78) | `PASSWORD_RESET_REQUESTED{outcome:LINK_SENT, email_domain:aust.edu}` → deliveries #7/#8 `PASSWORD_RESET` **`SENT`** through Gmail (2 s each) |
| Reset link opened `http://localhost:5173/reset-password?…`, new password set | `PASSWORD_RESET_COMPLETED` 15:22:25; `password_reset_tokens` rows for the account = **0** (token consumed) |
| Reused link | invalid-link screen (user-confirmed) |
| Old password rejected, new password accepted | user-confirmed at login; account `updated_at` moved |
| Security notice | `SECURITY_ALERT` "Your password was reset" notification + e-mail **`SENT`** via Gmail |
| Log / audit hygiene | `laravel.log`: 0 matches for the App Password or a `reset-password?token=` URL; audit metadata holds only `outcome` + `email_domain`; `email_deliveries` holds no body/token |

Total live messages sent: 6 (1 test, 3 reset, 2 security alerts) — no repeated bulk sends.

## 5. Security checks summary

| Requirement | Status |
|---|---|
| No credential in PHP/JS/TS/Python/Docker/docs/git | ✅ `.env.example` placeholders only; `.env` git-ignored (`git check-ignore backend/.env`) |
| Password masked in diagnostics | ✅ `email:check`, sanitizer, tests |
| Not returned by any API / not in frontend bundle | ✅ `/api/email/status` coarse; bundle scan clean |
| User A cannot modify user B's preferences | ✅ test + per-caller controller |
| Test endpoint abuse | ✅ auth + own-address rule + throttle + off in production |
| Header/HTML injection | ✅ recipient/subject validation, Blade escaping, URL sanitising |
| Account enumeration | ✅ identical bodies/status; dummy hash for timing; generic 429 |
| Token theft/reuse/expiry | ✅ hashed storage, single use, 60-min expiry, never logged |
| Preferences cannot silence security/auth e-mails | ✅ SECURITY mandatory; password reset bypasses preferences |
| E-mail failure never breaks academic operations | ✅ listeners guarded, EmailService never throws, rollback test |

## 6. Remaining issues / follow-ups

1. **Rotate the Gmail App Password.** During validation the App Password was pasted into the assistant chat; treat it
   as exposed — revoke it in *Google Account → Security → App passwords* and put a fresh one in `backend/.env` only.
2. `SentMessage::getMessageId()` returned `null` for both Mailpit and Gmail through Symfony's SMTP transport, so
   `email_deliveries.message_id` stays empty; the `X-FacultyLens-Delivery` header provides tracing instead.
3. `scripts/clean-start-test.sh` and the perf override were updated for the `horizon` service but were not re-run in
   this session.
4. Horizon's dashboard (`/horizon`) is served by the Laravel app; in production it is ADMIN-only via `viewHorizon`.
5. `ProfilePictureReplacementTest` showed one flaky failure on the host (no GD) during a full run and passed on
   re-run; it is unrelated to this change (GD tests are designed for Docker).
