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
