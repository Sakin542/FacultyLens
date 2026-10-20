# Admin control center

The administrator's workspace at `/admin`. It has its own layout (cream sidebar, top bar, breadcrumbs, Ctrl+K command palette, notification bell) and is separate from the faculty `DashboardLayout`.

**Principle:** AI assists, faculty decide. Nothing in the admin panel approves assessments, finalizes grades or edits official academic records.

## Access

- Only the account whose e-mail matches `ADMIN_EMAIL` **and** has the `ADMIN` role can open the panel (`EnsureAdmin` middleware on every `/api/admin/*` route). `php artisan admin:ensure` creates or repairs that account.
- The `ADMIN` role cannot be granted, removed or suspended from the panel, so the panel cannot lock out its own administrator.
- Frontend route guards are UX only; the server enforces every rule.

## Modules

| Area | Page | What it does |
| --- | --- | --- |
| Overview | Dashboard | Real KPIs for 7/30/90 days with previous-period comparison, daily trends, service states, critical alerts, recent admin activity, data-freshness line. Widgets can be hidden/reordered (saved in the browser). KPI CSV export. |
| | Task center | Every import, export, scan and health check with initiator, duration, counts, safe error and expiring result file. |
| | Calendar & schedule | Maintenance windows (suppress alert notifications for chosen services) and the live list of scheduled commands with next run time. |
| People & access | Users | Search/filter/sort; create account + set-password e-mail; edit name/department/designation; suspend (reason required) / reinstate; end sessions; reset 2FA (typed confirmation); bulk reinstate/suspend/role/department (confirmation required, admin skipped); CSV import with preview; CSV export; account history. |
| | Roles & permissions | The three fixed roles, a permission matrix naming the enforcing code, privileged-access inventory, role-change history. |
| | Security center | Failed sign-ins (one audit row per attempt), refused admin sign-ins, targeted accounts, source IPs, 2FA adoption, sessions, threshold signals (labelled as signals, not attacks). |
| | Audit log | Filter by scope/action/resource/date, search, inspect metadata, export. Read-only. |
| Institution | Academic structure | Departments (derived from accounts and programs), programs, terms, course metadata gaps. Read-only. |
| Operations | System health | Nine services, each `healthy / degraded / unavailable / unconfigured / unknown`, response time, history and hourly availability. "Probe now" (not recorded) or "Record check & evaluate alerts". |
| | Queues & jobs | Queue depth, failure trend, scrubbed failure summaries (payloads never shown), allow-listed retry, dismissal, link to Horizon. |
| | E-mail & notifications | Delivery status, latency (median/p95), templates, failure codes, notification volume. |
| | Alerts | Threshold rules evaluated every 5 minutes; one deduplicated alert per condition; auto-resolve; acknowledge / resolve (note required) / open incident. |
| | Incidents | Category, severity, status, timeline of every change and note, root cause (required to resolve), corrective actions, linked alerts and audit events. |
| | Backup & recovery | Backups with SHA-256 checksum; verification re-hashes and fully decompresses; restore checklist (restore stays console-only). |
| Data & AI | Data quality | 12 read-only checks; findings can be acknowledged, resolved or ignored (note required); re-scans close fixed findings and re-open recurring ones. |
| | AI governance | Model registry, recorded evaluation metrics only, faculty accept/override/reject rates, AI configuration presence and feature switches. |
| | AI usage | Calls, failures, latency and estimated tokens by operation and user. |
| | Reports & exports | Fixed CSV datasets (users, audit log, data quality, incidents, alerts, failed jobs, e-mail log); formula-injection safe; private and expiring. |
| Settings | Platform settings | Feature flags and announcement banner, with reason and change history. |
| | Environment | Version, drivers, whether each secret is configured (never its value), migration status. |
| | Help & coverage | State definitions, troubleshooting, and the list of modules that are intentionally not built. |

## Scheduled commands

| Command | When | Purpose |
| --- | --- | --- |
| `admin:monitor` | every 5 min | Record a health snapshot, write the scheduler heartbeat, evaluate alert rules |
| `admin:data-quality-scan` | daily 04:00 | Read-only data checks |
| `admin:prune` | daily 04:30 | Drop health history older than `ADMIN_HEALTH_HISTORY_DAYS`; delete expired export files |
| `backup:database --scheduled` | daily `BACKUP_SCHEDULE_TIME` | Database backup (now with checksum) |

A manual "Record check" does **not** write the heartbeat, so a stopped scheduler is never reported healthy.

## Configuration (`backend/config/admin.php`)

| Variable | Default | Meaning |
| --- | --- | --- |
| `ADMIN_ALERT_QUEUE_BACKLOG` | 200 | Pending jobs before a backlog alert (0 = off) |
| `ADMIN_ALERT_FAILED_JOBS_PER_HOUR` | 5 | Failed jobs per hour |
| `ADMIN_ALERT_EMAIL_FAILURES_PER_DAY` | 5 | Permanently failed e-mails per 24 h |
| `ADMIN_ALERT_BACKUP_MAX_AGE_HOURS` | 26 | Age of the last successful backup |
| `ADMIN_ALERT_FAILED_LOGINS_PER_HOUR` | 20 | Failed sign-ins per hour |
| `ADMIN_STORAGE_LOW_FREE_PERCENT` | 10 | Free disk below which storage is degraded |
| `ADMIN_PROBE_TIMEOUT_SECONDS` | 3 | Per-probe timeout |
| `ADMIN_HEARTBEAT_STALE_MINUTES` | 15 | Heartbeat age before the scheduler is unavailable |
| `ADMIN_HEALTH_HISTORY_DAYS` | 14 | Health history retention |
| `ADMIN_EXPORT_TTL_HOURS` / `ADMIN_EXPORT_MAX_ROWS` | 24 / 50000 | Export file lifetime and size |
| `ADMIN_IMPORT_MAX_ROWS` | 500 | Rows read from an import CSV |
| `APP_VERSION` | — | Version shown in Environment (falls back to the git commit) |

## Data model

Migration `2026_10_20_100001_create_admin_operations_tables`: `health_check_results`, `admin_alerts`, `admin_incidents`, `admin_incident_events`, `admin_operations`, `data_quality_findings`, `maintenance_windows`, and `checksum_sha256` / `verification_status` / `verified_at` on `database_backups`. All additive.

Failed sign-ins are now written to `audit_logs` as `AUTH_LOGIN_FAILED` (account id when the e-mail matches an account; the submitted e-mail and password are never stored).

## Security notes

- No endpoint returns secrets, `.env` values, job payloads, e-mail bodies or document text.
- Failed-job exception summaries strip file paths, SQL, e-mail addresses and long tokens.
- Retry is limited to job classes whose side effects are safe to repeat (`FailedJobInspector::RETRY_POLICY`).
- CSV exports prefix `= + - @` cells with `'` and exclude credentials; downloads are audited and expire.
- Sensitive actions require confirmation; suspensions, finding closures and alert resolutions require a reason; destructive ones (2FA reset, backup delete) require typing a word.
- Sensitive endpoints are rate-limited (`throttle:` on imports, exports, bulk changes, checks, scans, retries).

## Not built (and why)

Approval workflows, access-review campaigns, delegation and temporary elevation need a second privileged role; FacultyLens deliberately has one administrator. Also not built: custom roles/permission editor, workflow builder, scheduled report delivery, panel-based restore, re-sending failed e-mails (message context is not stored), retention/legal-hold/deletion-request management, API credential inventory, dependency scanning integration, localization and accessibility-audit tooling. The Help page lists these with reasons.

## Screenshots

`docs/images/admin/01-dashboard.png` … `18-mobile.png`, captured from the running Docker stack.
