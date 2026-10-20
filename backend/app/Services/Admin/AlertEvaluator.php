<?php

namespace App\Services\Admin;

use App\Events\SystemAlertRaised;
use App\Models\AdminAlert;
use App\Models\AuditLog;
use App\Models\DatabaseBackup;
use App\Models\EmailDelivery;
use App\Models\MaintenanceWindow;
use App\Services\Notification\NotificationRecipientResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns measured conditions into admin alerts. Each condition has a stable dedupe key: while it persists the same
 * alert row is updated (occurrences, last_seen_at); once it clears, the open alert resolves itself. New alerts notify
 * the administrator through the existing notification system unless a maintenance window suppresses them.
 *
 * Rules only report what was measured. The failed sign-in rule flags unusual volume; it does not claim an attack.
 */
class AlertEvaluator
{
    public function __construct(protected NotificationRecipientResolver $recipients) {}

    /**
     * @param  array<string, array{status: string, label: string, summary: string}>  $health  ServiceMonitor results
     * @return array{raised: int, updated: int, resolved: int, active: list<string>}
     */
    public function evaluate(array $health): array
    {
        $conditions = $this->conditions($health);
        $stats = ['raised' => 0, 'updated' => 0, 'resolved' => 0, 'active' => array_keys($conditions)];

        foreach ($conditions as $key => $c) {
            $existing = AdminAlert::query()->where('dedupe_key', $key)->first();
            if ($existing && $existing->status !== 'resolved') {
                $existing->forceFill([
                    'occurrences' => $existing->occurrences + 1,
                    'last_seen_at' => now(),
                    'last_value' => $c['value'],
                    'message' => $c['message'],
                    'severity' => $c['severity'],
                ])->save();
                $stats['updated']++;
                continue;
            }
            $suppressed = $this->suppressed($c['service']);
            $attributes = [
                'rule' => $c['rule'], 'severity' => $c['severity'], 'service' => $c['service'], 'title' => $c['title'],
                'message' => $c['message'], 'status' => 'open', 'occurrences' => 1, 'last_value' => $c['value'],
                'first_seen_at' => now(), 'last_seen_at' => now(), 'suppressed' => $suppressed, 'notified_at' => null,
                'acknowledged_by' => null, 'acknowledged_at' => null, 'resolved_by' => null, 'resolved_at' => null,
                'resolution_note' => null, 'incident_id' => null,
            ];
            // A resolved alert re-opens on its own row (dedupe keys are unique), keeping one history line per condition
            if ($existing) {
                $existing->forceFill($attributes)->save();
                $alert = $existing;
            } else {
                $alert = AdminAlert::query()->create(['dedupe_key' => $key] + $attributes);
            }
            if (!$suppressed) {
                $this->notify($alert);
            }
            $stats['raised']++;
        }

        // Conditions that are no longer measured resolve automatically (acknowledged ones too)
        $cleared = AdminAlert::query()->whereIn('status', ['open', 'acknowledged'])->whereNotIn('dedupe_key', array_keys($conditions) ?: ['__none__'])->get();
        foreach ($cleared as $alert) {
            $alert->forceFill(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => null, 'resolution_note' => 'Condition cleared on re-check.'])->save();
            $stats['resolved']++;
        }

        return $stats;
    }

    /** @return array<string, array{rule: string, severity: string, service: ?string, title: string, message: string, value: array}> */
    public function conditions(array $health): array
    {
        $out = [];

        foreach ($health as $service => $r) {
            if (($r['status'] ?? null) === ServiceMonitor::UNAVAILABLE) {
                $out["service_unavailable:{$service}"] = [
                    'rule' => 'service_unavailable',
                    'severity' => in_array($service, ['database', 'ai_service', 'queue', 'workers'], true) ? 'critical' : 'warning',
                    'service' => $service,
                    'title' => ($r['label'] ?? $service).' unavailable',
                    'message' => (string) ($r['summary'] ?? 'Health probe failed.'),
                    'value' => ['status' => $r['status']],
                ];
            }
        }

        $t = (array) config('admin.alerts');

        if (($limit = (int) ($t['queue_backlog'] ?? 0)) > 0 && isset($health['queue']['detail']['pending']) && $health['queue']['detail']['pending'] > $limit) {
            $pending = (int) $health['queue']['detail']['pending'];
            $out['queue_backlog'] = $this->condition('queue_backlog', 'warning', 'queue', 'Queue backlog above threshold', "{$pending} jobs are waiting (threshold {$limit}).", ['pending' => $pending, 'threshold' => $limit]);
        }

        $this->safely(function () use (&$out, $t) {
            if (($limit = (int) ($t['failed_jobs_per_hour'] ?? 0)) > 0) {
                $n = DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour())->count();
                if ($n >= $limit) {
                    $out['failed_jobs'] = $this->condition('failed_jobs', 'warning', 'queue', 'Excessive failed jobs', "{$n} jobs failed in the last hour (threshold {$limit}).", ['failed_last_hour' => $n, 'threshold' => $limit]);
                }
            }
        });

        $this->safely(function () use (&$out, $t) {
            if (($limit = (int) ($t['email_failures_per_day'] ?? 0)) > 0) {
                $n = EmailDelivery::query()->where('status', EmailDelivery::FAILED)->where('failed_at', '>=', now()->subDay())->count();
                if ($n >= $limit) {
                    $out['email_failures'] = $this->condition('email_failures', 'warning', 'mail', 'E-mail delivery failures', "{$n} e-mails failed permanently in 24 h (threshold {$limit}).", ['failed_24h' => $n, 'threshold' => $limit]);
                }
            }
        });

        $this->safely(function () use (&$out, $t) {
            if (($hours = (int) ($t['backup_max_age_hours'] ?? 0)) > 0) {
                $latest = DatabaseBackup::query()->where('status', DatabaseBackup::STATUS_COMPLETED)->latest('completed_at')->first();
                if (!$latest || $latest->completed_at < now()->subHours($hours)) {
                    $age = $latest ? (int) $latest->completed_at->diffInHours(now()) : null;
                    $out['backup_stale'] = $this->condition('backup_stale', 'warning', 'backups', 'No recent successful backup',
                        $latest ? "The last successful backup is {$age} h old (threshold {$hours} h)." : 'No successful database backup has been recorded.',
                        ['age_hours' => $age, 'threshold' => $hours]);
                }
                $last = DatabaseBackup::query()->latest('id')->first();
                if ($last && $last->status === DatabaseBackup::STATUS_FAILED) {
                    $out["backup_failed:{$last->id}"] = $this->condition('backup_failed', 'warning', 'backups', 'Latest backup failed', "Backup {$last->filename} failed.", ['backup_id' => $last->id]);
                }
            }
        });

        $this->safely(function () use (&$out, $t) {
            if (($limit = (int) ($t['failed_logins_per_hour'] ?? 0)) > 0) {
                $n = AuditLog::query()->where('action', 'AUTH_LOGIN_FAILED')->where('created_at', '>=', now()->subHour())->count();
                if ($n >= $limit) {
                    $out['failed_logins'] = $this->condition('failed_logins', 'warning', 'auth', 'Unusual volume of failed sign-ins',
                        "{$n} failed sign-in attempts in the last hour (threshold {$limit}). Review the security center; this is a volume signal, not a confirmed attack.",
                        ['failed_last_hour' => $n, 'threshold' => $limit]);
                }
            }
        });

        if (($health['storage']['status'] ?? null) === ServiceMonitor::DEGRADED && isset($health['storage']['detail']['free_percent'])) {
            $pct = $health['storage']['detail']['free_percent'];
            $out['storage_low'] = $this->condition('storage_low', 'warning', 'storage', 'Storage running low', "Only {$pct}% of disk space is free.", ['free_percent' => $pct]);
        }

        return $out;
    }

    public function suppressed(?string $service): bool
    {
        try {
            return MaintenanceWindow::query()->activeAt()->where('suppress_alerts', true)->get()
                ->contains(fn (MaintenanceWindow $w) => empty($w->services) || ($service !== null && in_array($service, $w->services, true)));
        } catch (Throwable) {
            return false;
        }
    }

    protected function notify(AdminAlert $alert): void
    {
        try {
            $admins = $this->recipients->admins();
            if ($admins->isEmpty()) {
                return;
            }
            event(new SystemAlertRaised(
                $admins,
                $alert->title,
                $alert->message,
                ['source' => 'admin_alert', 'alert_id' => $alert->id, 'rule' => $alert->rule, 'url' => '/admin/alerts'],
                'ADMIN_ALERT:'.$alert->dedupe_key.':'.$alert->first_seen_at?->timestamp,
                $alert->severity === 'critical' ? 'CRITICAL' : 'WARNING',
            ));
            $alert->forceFill(['notified_at' => now()])->save();
        } catch (Throwable $e) {
            Log::warning('Admin alert notification failed: '.class_basename($e));
        }
    }

    protected function condition(string $rule, string $severity, ?string $service, string $title, string $message, array $value): array
    {
        return compact('rule', 'severity', 'service', 'title', 'message', 'value');
    }

    protected function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            Log::warning('Alert rule skipped: '.class_basename($e));
        }
    }
}
