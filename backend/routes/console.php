<?php

use App\Services\InstitutionalReportService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// STEP 39: remove expired institutional report files from private storage (records + audit rows are kept)
Artisan::command('reports:purge-expired', function (InstitutionalReportService $reports) {
    $n = $reports->purgeExpired();
    $this->info("Purged {$n} expired report file(s).");
})->purpose('Delete expired institutional report files');

Schedule::command('reports:purge-expired')->dailyAt('02:30');

// STEP 47: notification retention (expired + stale read/dismissed rows; audit rows are never touched)
Artisan::command('notifications:purge', function (\App\Services\Notification\NotificationService $notifications) {
    $n = $notifications->purge();
    $this->info("Purged {$n} notification(s).");
})->purpose('Delete expired and stale notifications according to config/notifications.php retention');

Schedule::command('notifications:purge')->dailyAt('03:00');

// Faculty dispatch newsletter: send an issue to every confirmed subscriber, and drop stale unconfirmed sign-ups
Artisan::command('newsletter:send {subject} {--body= : plain-text body} {--body-file= : path to a plain-text/markdown body} {--action-text=} {--action-url=} {--dry-run : only report the recipient count}', function (\App\Services\NewsletterService $newsletter) {
    $body = (string) $this->option('body');
    if ($file = $this->option('body-file')) {
        if (!is_readable($file)) {
            $this->error("Body file not readable: {$file}");

            return 1;
        }
        $body = (string) file_get_contents($file);
    }
    if (trim($body) === '') {
        $this->error('Provide --body or --body-file.');

        return 1;
    }
    $count = $newsletter->activeCount();
    if ($this->option('dry-run')) {
        $this->info("Dry run: {$count} confirmed subscriber(s) would receive \"{$this->argument('subject')}\".");

        return 0;
    }
    if ($count > 0 && !$this->confirm("Send \"{$this->argument('subject')}\" to {$count} subscriber(s)?", true)) {
        return 1;
    }
    $sent = $newsletter->sendDispatch((string) $this->argument('subject'), $body, $this->option('action-text') ?: null, $this->option('action-url') ?: null);
    $this->info("Dispatch queued for {$sent} subscriber(s).");

    return 0;
})->purpose('Send a Faculty dispatch issue to all confirmed newsletter subscribers');

Artisan::command('newsletter:purge-unconfirmed', function (\App\Services\NewsletterService $newsletter) {
    $n = $newsletter->purgeUnconfirmed();
    $this->info("Purged {$n} unconfirmed subscription(s).");
})->purpose('Delete newsletter sign-ups that were never confirmed');

Schedule::command('newsletter:purge-unconfirmed')->dailyAt('03:15');

// E-mail delivery log retention (metadata rows only; audit rows are never touched)
Artisan::command('email:purge-deliveries', function () {
    $days = (int) config('email.retention_days', 0);
    if ($days <= 0) {
        $this->info('Retention disabled (EMAIL_DELIVERY_RETENTION_DAYS=0).');

        return 0;
    }
    $n = \App\Models\EmailDelivery::query()->where('created_at', '<', now()->subDays($days))->delete();
    $this->info("Purged {$n} e-mail delivery record(s) older than {$days} days.");

    return 0;
})->purpose('Delete old email_deliveries rows according to config/email.php retention');

Schedule::command('email:purge-deliveries')->dailyAt('03:30');

// Admin panel: create/repair the single administrator account from ADMIN_EMAIL / ADMIN_PASSWORD (idempotent).
// Any other account holding the ADMIN role is demoted to FACULTY so the panel stays restricted to one login.
Artisan::command('admin:ensure {--reset-password : also reset the password to ADMIN_PASSWORD for an existing account}', function () {
    $email = (string) config('admin.email');
    $password = (string) config('admin.password');
    if ($email === '') {
        $this->error('Set ADMIN_EMAIL in backend/.env first.');

        return 1;
    }

    $admin = \App\Models\User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
    if (!$admin) {
        if ($password === '') {
            $this->error('Set ADMIN_PASSWORD in backend/.env to create the administrator account.');

            return 1;
        }
        $admin = \App\Models\User::create([
            'name' => (string) config('admin.name'),
            'email' => $email,
            'department' => 'Administration',
            'designation' => 'System Administrator',
            'password' => $password,
        ]);
        $this->info("Created administrator {$email}.");
    } elseif ($this->option('reset-password')) {
        if ($password === '') {
            $this->error('ADMIN_PASSWORD is empty; nothing to reset to.');

            return 1;
        }
        $admin->password = $password;
        $this->info('Administrator password reset.');
    }
    $admin->forceFill(['role' => 'ADMIN', 'is_active' => true, 'email_verified_at' => $admin->email_verified_at ?? now()])->save();

    $demoted = \App\Models\User::query()->where('role', 'ADMIN')->whereRaw('LOWER(email) <> ?', [$email])->get();
    foreach ($demoted as $user) {
        $user->forceFill(['role' => 'FACULTY'])->save();
        $this->warn("Demoted {$user->email} from ADMIN to FACULTY.");
    }
    $this->info("Administrator ready: {$email}");

    return 0;
})->purpose('Create or repair the single FacultyLens administrator account');

// Admin panel: gzipped database backup into private storage (also available as "Back up now" in the admin panel)
Artisan::command('backup:database {--scheduled : record the run as scheduled} {--if-due : only run when no successful backup is newer than BACKUP_INTERVAL_HOURS}', function (\App\Services\Admin\DatabaseBackupService $backups) {
    if ($this->option('if-due') && !$backups->isDue()) {
        $this->info('A recent backup exists; nothing to do.');

        return 0;
    }
    $backup = $backups->run($this->option('scheduled') ? 'scheduled' : 'manual');
    if ($backup->status !== \App\Models\DatabaseBackup::STATUS_COMPLETED) {
        $this->error("Backup failed: {$backup->error}");

        return 1;
    }
    $this->info(sprintf('Backup %s created (%s KB)%s.', $backup->filename, number_format($backup->size_bytes / 1024, 1),
        $backup->files_count ? sprintf(' with %d uploaded files (%s KB)', $backup->files_count, number_format($backup->files_size_bytes / 1024, 1)) : ''));
    if ($backup->error) {
        $this->warn($backup->error);
    }

    return 0;
})->purpose('Create a gzipped database backup in storage/app/private/backups');

// Checked hourly rather than once at a fixed time: Laravel never runs a missed schedule, so a PC that was off or asleep
// at 02:00 would skip that day's backup. The backup runs as soon as the newest one is BACKUP_INTERVAL_HOURS old.
Schedule::command('backup:database --scheduled --if-due')->hourly()->withoutOverlapping();

// Academic calendar reminders (in-app + e-mail through the notification system)
Artisan::command('calendar:send-reminders', function (\App\Services\Planning\CalendarService $calendar) {
    $n = $calendar->sendDueReminders();
    $this->info("Sent {$n} calendar reminder(s).");
})->purpose('Send due academic calendar reminders');

Schedule::command('calendar:send-reminders')->everyFiveMinutes()->withoutOverlapping();

// Restore is deliberately CLI-only: it overwrites every table in the current database.
Artisan::command('backup:restore {backup : backup id or filename} {--force : skip the confirmation prompt}', function (\App\Services\Admin\DatabaseBackupService $backups) {
    $key = (string) $this->argument('backup');
    $backup = \App\Models\DatabaseBackup::query()->where('id', ctype_digit($key) ? (int) $key : 0)->orWhere('filename', $key)->first();
    $path = $backup ? $backups->absolutePath($backup) : null;
    if (!$backup || !$path) {
        $this->error('Backup not found.');

        return 1;
    }
    $connection = (array) config('database.connections.'.config('database.default'));
    if (!in_array($connection['driver'] ?? '', ['mysql', 'mariadb'], true)) {
        $this->error('backup:restore supports MySQL/MariaDB only.');

        return 1;
    }
    if (!$this->option('force') && !$this->confirm("Restore {$backup->filename}? This REPLACES all data in {$connection['database']}.")) {
        return 1;
    }

    $sql = gzdecode((string) file_get_contents($path));
    $result = \Illuminate\Support\Facades\Process::timeout((int) config('admin.backups.timeout_seconds', 600))
        ->env(['MYSQL_PWD' => (string) ($connection['password'] ?? '')])
        ->input($sql)
        ->run(['mysql', '--host='.($connection['host'] ?? '127.0.0.1'), '--port='.($connection['port'] ?? 3306), '--user='.($connection['username'] ?? 'root'), (string) $connection['database']]);
    if (!$result->successful()) {
        $this->error('Restore failed: '.trim($result->errorOutput()));

        return 1;
    }
    $this->info("Restored {$backup->filename}.");

    return 0;
})->purpose('Restore a database backup created by backup:database (MySQL/MariaDB)');

// STEP 47: operator-raised SYSTEM_ALERT (e.g. planned maintenance, AI service incident) to all admins or to given user ids
Artisan::command('notifications:system-alert {title} {message} {--user=* : user ids (default: all ADMIN users)} {--severity=WARNING} {--key= : dedupe key}', function (\App\Services\Notification\NotificationRecipientResolver $resolver) {
    $users = $this->option('user') ? \App\Models\User::whereIn('id', array_map('intval', (array) $this->option('user')))->get() : $resolver->admins();
    if ($users->isEmpty()) {
        $this->warn('No recipients.');

        return 1;
    }
    event(new \App\Events\SystemAlertRaised($users, (string) $this->argument('title'), (string) $this->argument('message'), ['source' => 'operator'], $this->option('key') ?: null, strtoupper((string) $this->option('severity'))));
    $this->info('System alert queued for ' . $users->count() . ' recipient(s).');

    return 0;
})->purpose('Raise a SYSTEM_ALERT notification for administrators or specific users');

// Admin control center: record a health snapshot (also the scheduler heartbeat) and evaluate alert rules
Artisan::command('admin:monitor', function (\App\Services\Admin\ServiceMonitor $monitor, \App\Services\Admin\AlertEvaluator $alerts) {
    $results = $monitor->record(heartbeat: true);
    $stats = $alerts->evaluate($results);
    foreach ($results as $r) {
        $this->line(sprintf('%-14s %-12s %s', $r['service'], $r['status'], $r['summary']));
    }
    $this->info("Alerts: {$stats['raised']} raised, {$stats['updated']} still active, {$stats['resolved']} resolved.");

    return 0;
})->purpose('Record service health and evaluate admin alert rules');

Schedule::command('admin:monitor')->everyFiveMinutes()->withoutOverlapping();

// Read-only data-quality scan (findings only; academic records are never modified)
Artisan::command('admin:data-quality-scan', function (\App\Services\Admin\DataQualityScanner $scanner, \App\Services\Admin\OperationTracker $operations) {
    $op = $operations->run('data_quality_scan', 'Data-quality scan (scheduled)', null, function () use ($scanner) {
        $r = $scanner->scan();

        return ['total' => $r['findings'], 'success' => $r['checks'], 'failure' => 0, 'summary' => $r];
    });
    if ($op->status !== 'completed') {
        $this->error('Scan failed: '.$op->error);

        return 1;
    }
    $this->info(sprintf('%d finding(s): %d new, %d no longer detected.', $op->summary['findings'], $op->summary['new'], $op->summary['auto_resolved']));

    return 0;
})->purpose('Run the read-only data-quality checks');

Schedule::command('admin:data-quality-scan')->dailyAt('04:00')->withoutOverlapping();

// Health history retention and expired admin export files (operation rows are kept)
Artisan::command('admin:prune', function (\App\Services\Admin\ServiceMonitor $monitor, \App\Services\Admin\OperationTracker $operations) {
    $this->info(sprintf('Pruned %d health check row(s) and %d expired export file(s).', $monitor->prune(), $operations->purgeExpiredResults()));
})->purpose('Prune admin health history and expired export files');

Schedule::command('admin:prune')->dailyAt('04:30');
