<?php

namespace App\Services\Admin;

use App\Models\AdminAlert;
use App\Models\AdminIncident;
use App\Models\AuditLog;
use App\Models\DataQualityFinding;
use App\Models\EmailDelivery;
use App\Models\User;
use App\Support\SafeCsv;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin CSV exports. Columns are fixed per dataset (no arbitrary field selection, no SQL), secrets are never
 * included, e-mail recipients in delivery logs stay masked, and every cell is formula-injection safe.
 */
class AdminExportService
{
    public const DATASETS = [
        'users' => 'User and role inventory',
        'audit_logs' => 'Audit log',
        'data_quality' => 'Data-quality findings',
        'incidents' => 'Incident history',
        'alerts' => 'Alert history',
        'failed_jobs' => 'Failed background jobs',
        'email_deliveries' => 'E-mail delivery log',
    ];

    /** @return array{name: string, contents: string, rows: int} */
    public function build(string $dataset, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $max = max(1, (int) config('admin.exports.max_rows', 50000));
        [$header, $rows] = match ($dataset) {
            'users' => $this->users($max),
            'audit_logs' => $this->auditLogs($from, $to, $max),
            'data_quality' => $this->dataQuality($max),
            'incidents' => $this->incidents($from, $to, $max),
            'alerts' => $this->alerts($from, $to, $max),
            'failed_jobs' => $this->failedJobs($from, $to, $max),
            'email_deliveries' => $this->emailDeliveries($from, $to, $max),
            default => throw new AdminOperationException('Unknown export dataset.'),
        };

        return [
            'name' => sprintf('facultylens-%s-%s.csv', str_replace('_', '-', $dataset), now()->format('Ymd-His')),
            'contents' => SafeCsv::build($header, $rows),
            'rows' => count($rows),
        ];
    }

    protected function users(int $max): array
    {
        $rows = User::query()->withCount('courses')->orderBy('id')->limit($max)->get()->map(fn (User $u) => [
            $u->id, $u->name, $u->email, $u->role ?? 'FACULTY', $u->isActive() ? 'active' : 'deactivated', $u->department, $u->designation,
            $u->courses_count, $u->hasTwoFactorEnabled(), $u->email_verified_at?->toIso8601String(), $u->last_login_at?->toIso8601String(), $u->created_at?->toIso8601String(),
        ])->all();

        return [['id', 'name', 'email', 'role', 'status', 'department', 'designation', 'courses', 'two_factor', 'email_verified_at', 'last_login_at', 'created_at'], $rows];
    }

    protected function auditLogs(?Carbon $from, ?Carbon $to, int $max): array
    {
        $rows = $this->range(AuditLog::query()->with('user:id,name'), 'created_at', $from, $to)->latest('created_at')->limit($max)->get()
            ->map(fn (AuditLog $l) => [$l->id, $l->created_at?->toIso8601String(), $l->user?->name ?? 'System', $l->user_id, $l->action, $l->entity_type, $l->entity_id, $l->ip_address, $l->metadata])
            ->all();

        return [['id', 'timestamp', 'actor', 'actor_id', 'action', 'resource_type', 'resource_id', 'ip_address', 'metadata'], $rows];
    }

    protected function dataQuality(int $max): array
    {
        $rows = DataQualityFinding::query()->orderByDesc('last_detected_at')->limit($max)->get()->map(fn (DataQualityFinding $f) => [
            $f->id, $f->check, $f->category, $f->severity, $f->status, $f->entity_type, $f->entity_id, $f->entity_label, $f->explanation,
            $f->remediation, $f->first_detected_at?->toIso8601String(), $f->last_detected_at?->toIso8601String(), $f->resolved_at?->toIso8601String(),
        ])->all();

        return [['id', 'check', 'category', 'severity', 'status', 'resource_type', 'resource_id', 'resource', 'explanation', 'remediation', 'first_detected_at', 'last_detected_at', 'resolved_at'], $rows];
    }

    protected function incidents(?Carbon $from, ?Carbon $to, int $max): array
    {
        $rows = $this->range(AdminIncident::query()->with(['openedBy:id,name', 'assignee:id,name']), 'opened_at', $from, $to)->latest('opened_at')->limit($max)->get()
            ->map(fn (AdminIncident $i) => [
                sprintf('INC-%04d', $i->id), $i->title, $i->category, $i->severity, $i->status, $i->service, $i->openedBy?->name, $i->assignee?->name,
                $i->opened_at?->toIso8601String(), $i->resolved_at?->toIso8601String(), $i->root_cause, $i->corrective_actions,
            ])->all();

        return [['reference', 'title', 'category', 'severity', 'status', 'service', 'opened_by', 'assignee', 'opened_at', 'resolved_at', 'root_cause', 'corrective_actions'], $rows];
    }

    protected function alerts(?Carbon $from, ?Carbon $to, int $max): array
    {
        $rows = $this->range(AdminAlert::query(), 'first_seen_at', $from, $to)->latest('first_seen_at')->limit($max)->get()
            ->map(fn (AdminAlert $a) => [$a->id, $a->rule, $a->severity, $a->service, $a->title, $a->status, $a->occurrences, $a->suppressed, $a->first_seen_at?->toIso8601String(), $a->last_seen_at?->toIso8601String(), $a->resolved_at?->toIso8601String(), $a->resolution_note])
            ->all();

        return [['id', 'rule', 'severity', 'service', 'title', 'status', 'occurrences', 'suppressed', 'first_seen_at', 'last_seen_at', 'resolved_at', 'resolution_note'], $rows];
    }

    protected function failedJobs(?Carbon $from, ?Carbon $to, int $max): array
    {
        $rows = $this->range(DB::table('failed_jobs'), 'failed_at', $from, $to)->orderByDesc('failed_at')->limit($max)->get()
            ->map(function ($j) {
                $s = FailedJobInspector::summarize($j);

                return [$s['uuid'], $s['job'], $s['queue'], $s['connection'], $s['exception'], $s['failed_at']];
            })->all();

        return [['uuid', 'job', 'queue', 'connection', 'exception', 'failed_at'], $rows];
    }

    protected function emailDeliveries(?Carbon $from, ?Carbon $to, int $max): array
    {
        $rows = $this->range(EmailDelivery::query(), 'created_at', $from, $to)->latest('id')->limit($max)->get()
            ->map(fn (EmailDelivery $d) => [$d->id, $d->type, $d->template, EmailDelivery::maskAddress($d->recipient), $d->status, $d->attempts, $d->error_code, $d->queued_at?->toIso8601String(), $d->sent_at?->toIso8601String(), $d->failed_at?->toIso8601String()])
            ->all();

        return [['id', 'type', 'template', 'recipient_masked', 'status', 'attempts', 'error_code', 'queued_at', 'sent_at', 'failed_at'], $rows];
    }

    protected function range($query, string $column, ?Carbon $from, ?Carbon $to)
    {
        return $query->when($from, fn ($q) => $q->where($column, '>=', $from->copy()->startOfDay()))
            ->when($to, fn ($q) => $q->where($column, '<=', $to->copy()->endOfDay()));
    }
}
