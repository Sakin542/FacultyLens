<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminIncident extends Model
{
    public const CATEGORIES = ['availability', 'ai', 'security', 'data', 'email', 'performance', 'other'];
    public const SEVERITIES = ['critical', 'high', 'medium', 'low'];
    public const STATUSES = ['open', 'investigating', 'mitigated', 'resolved', 'closed'];
    public const ACTIVE_STATUSES = ['open', 'investigating', 'mitigated'];

    protected $fillable = [
        'title', 'category', 'severity', 'status', 'service', 'summary', 'root_cause', 'corrective_actions',
        'opened_by', 'assigned_to', 'opened_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AdminIncidentEvent::class, 'incident_id')->orderBy('created_at')->orderBy('id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(AdminAlert::class, 'incident_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function toPayload(bool $detailed = false): array
    {
        $payload = [
            'id' => $this->id,
            'reference' => sprintf('INC-%04d', $this->id),
            'title' => $this->title,
            'category' => $this->category,
            'severity' => $this->severity,
            'status' => $this->status,
            'service' => $this->service,
            'opened_by' => $this->openedBy?->only(['id', 'name']),
            'assignee' => $this->assignee?->only(['id', 'name']),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
        if ($detailed) {
            $payload += [
                'summary' => $this->summary,
                'root_cause' => $this->root_cause,
                'corrective_actions' => $this->corrective_actions,
                'events' => $this->events->map(fn (AdminIncidentEvent $e) => $e->toPayload())->all(),
                'alerts' => $this->alerts->map(fn (AdminAlert $a) => $a->toPayload())->all(),
            ];
        }

        return $payload;
    }
}
