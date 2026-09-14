# FacultyLens Email Configuration

## 1. Environment variables

All mail settings are read from the environment by `config/mail.php` and `config/email.php`. Nothing is hard-coded
in PHP, JavaScript, Python, Docker files or documentation. The real Gmail App Password is typed into the **local**
`backend/.env` only.

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=saomiorashid542@gmail.com
MAIL_PASSWORD=                      # Gmail App Password — local .env only, never committed
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=saomiorashid542@gmail.com
MAIL_FROM_NAME="FacultyLens"
```

| Variable | Purpose |
|---|---|
| `MAIL_MAILER` | `smtp` (Gmail), `mailpit` (local capture for E2E), `log` (write to laravel.log), `array` (tests) |
| `MAIL_HOST` / `MAIL_PORT` / `MAIL_ENCRYPTION` | Gmail: `smtp.gmail.com`, `587`, `tls` (STARTTLS). Alternative: port `465` with `MAIL_SCHEME=smtps` |
| `MAIL_USERNAME` | The Gmail account that sends |
| `MAIL_PASSWORD` | A 16-character **App Password**, not the account password |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | Must be the same Gmail account (Gmail rewrites other From addresses) |
| `MAIL_TIMEOUT` | SMTP socket timeout (default 30 s) |
| `FRONTEND_URL` | Public origin used for every link in an e-mail (reset links, CTAs, preferences) |
| `QUEUE_CONNECTION` | `redis` (Horizon). `database` also works with `queue:work --queue=emails,default` |
| `REDIS_HOST` / `REDIS_CLIENT` | `redis` / `predis` inside Docker |
| `EMAIL_*` | See `backend/.env.example` (enabled switch, queue name, tries, limits, test/preview toggles) |
| `HORIZON_*` | Supervisor process counts and timeouts |

`.gitignore` in `backend/` contains `.env`, `.env.*`, `!.env.example` — the real file is never committed.
`.env.example` and `.env.production.example` carry placeholders only.

## 2. Gmail App Password setup

1. Sign in to the sender Google account → **Manage your Google Account → Security**.
2. Turn on **2-Step Verification** (App Passwords are unavailable without it).
3. Open **App passwords** (search “App passwords” in the account settings), choose *Mail* / *Other (FacultyLens)*
   and generate. Google shows a 16-character code once.
4. Put the code in `backend/.env` as `MAIL_PASSWORD=xxxxxxxxxxxxxxxx` (spaces may be omitted).
5. Never paste it into chat, tickets, README files or commits. If it is ever exposed, revoke it in the same screen
   and generate a new one.

Gmail limits: ~500 messages/day for a personal account (2,000 for Workspace). Temporary `421`/`450` replies are
retried automatically; a `535 Username and Password not accepted` reply is permanent and usually means the App
Password is wrong or 2-Step Verification was turned off.

## 3. Verify the configuration

```bash
# host (Windows: cmd //c "php artisan …")
php artisan config:clear
php artisan email:check
```

The command prints every `MAIL_*` value with the password masked (`********`) and lists missing settings. Never
`dd(config('mail'))` or `cat .env` on a shared screen.

Inside Docker (the value that Horizon actually uses):

```bash
docker compose exec -T app php artisan config:clear
docker compose exec -T app php artisan email:check
docker compose exec -T app php -r '$s=fsockopen("smtp.gmail.com",587,$e,$m,10); echo $s ? fgets($s) : "FAIL $m";'
```

After changing `.env` restart the queue workers — Horizon caches configuration at boot:

```bash
docker compose restart horizon          # or: php artisan horizon:terminate
```

If production uses `php artisan config:cache`, rebuild it (`config:cache`) after every change and never commit
`bootstrap/cache/config.php`.

## 4. Docker services (development)

`docker-compose.yml` runs:

| Service | Role |
|---|---|
| `app` | Laravel (`php artisan serve`, :8080) |
| `horizon` | `php artisan horizon` — supervisors `supervisor-default` (queue `default`) and `supervisor-emails` (queue `emails`) |
| `redis` | Queue backend (:6379) |
| `mailpit` | Local SMTP capture, UI http://localhost:8025 — set `MAIL_MAILER=mailpit` to route mail here |
| `mysql`, `phpmyadmin`, `ai-service` | unchanged |

The former `queue-worker` (database queue) container is replaced by `horizon`. The dev image installs `pcntl`
(required by Horizon); `Dockerfile.prod` already had it.

Production (`docker-compose.prod.yml`) replaces the `worker` service with `horizon`. Sizing: `HORIZON_DEFAULT_PROCESSES`
(default 4), `HORIZON_EMAIL_PROCESSES` (default 2). Keep `REDIS_QUEUE_RETRY_AFTER` (1900) above the longest job
timeout.

## 5. Switching transports

| Goal | Setting |
|---|---|
| Real delivery through Gmail | `MAIL_MAILER=smtp` (+ App Password) |
| Local capture for development / Playwright | `MAIL_MAILER=mailpit` |
| No transport at all (log only) | `MAIL_MAILER=log` |
| Disable e-mail while keeping in-app notifications | `EMAIL_ENABLED=false` |

Automated tests use `MAIL_MAILER=array` and `QUEUE_CONNECTION=sync` (`phpunit.xml`) and never touch Gmail. The single
live SMTP test only runs with `EMAIL_LIVE_TESTING=true`.

## 6. Troubleshooting

| Symptom | Where to look | Likely cause |
|---|---|---|
| Delivery stays `PENDING`, `attempts=0` | `docker compose ps horizon`, `/horizon` | Horizon not running / wrong `QUEUE_CONNECTION`; a job on `database` queue when Horizon reads Redis |
| `SMTP_AUTHENTICATION_FAILED` | `email_deliveries.error_code` | Wrong App Password, account password used instead, 2-Step Verification disabled |
| `SMTP_TIMEOUT` / `SMTP_CONNECTION_FAILED` | `fsockopen` test inside the container | Egress on 587 blocked; try 465 + `MAIL_SCHEME=smtps` |
| `DNS_RESOLUTION_FAILED` | container DNS | Docker network / VPN DNS |
| `SMTP_RATE_LIMITED` | Gmail reply | Daily quota reached; retried with backoff |
| `TEMPLATE_RENDER_FAILED` | `storage/logs/laravel.log` | Missing view or variable — preview with `GET /api/email/preview/{template}` |
| E-mail arrives but links point to the wrong host | `FRONTEND_URL` | Set to the public frontend origin |
| Gmail shows a different From | Gmail policy | `MAIL_FROM_ADDRESS` must equal `MAIL_USERNAME` |
| Preference saved but no e-mail | `/api/notification-preferences` → `email_available` | `MAIL_MAILER=log/array` or `EMAIL_ENABLED=false` |

Logs contain only: type, template, recipient domain, status, delivery id, error code. Transport messages pass
through `EmailLogSanitizer` — if you ever see a credential in a log line, treat it as exposed and rotate it.
