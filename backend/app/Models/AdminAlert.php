<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A measured condition that crossed a configured threshold (see App\Services\Admin\AlertEvaluator). One row per
 * dedupe key while unresolved; repeat detections bump occurrences/last_seen_at instead of creating new rows.
 */
class AdminAlert extends Model
{
    public const STATUSES = ['open', 'acknowledged', 'resolved'];

    protected $fillable = [
        'rule', 'dedupe_key', 'severity', 'service', 'title', 'message', 'status', 'occurrences', 'last_value',
        'first_seen_at', 'last_seen_at', 'suppressed', 'notified_at', 'acknowledged_by', 'acknowledged_at',
        'resolved_by', 'resolved_at', 'resolution_note', 'incident_id',
    ];

    protected function casts(): array
    {
        return [
            'last_value' => 'array',
            'occurrences' => 'integer',
            'suppressed' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'notified_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(AdminIncident::class, 'incident_id');
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'rule' => $this->rule,
            'severity' => $this->severity,
            'service' => $this->service,
            'title' => $this->title,
            'message' => $this->message,
            'status' => $this->status,
            'occurrences' => $this->occurrences,
            'last_value' => $this->last_value,
            'suppressed' => $this->suppressed,
            'notified' => $this->notified_at !== null,
            'first_seen_at' => $this->first_seen_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'acknowledged_by' => $this->acknowledgedBy?->only(['id', 'name']),
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            'resolved_by' => $this->resolvedBy?->only(['id', 'name']),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolution_note' => $this->resolution_note,
            'incident_id' => $this->incident_id,
        ];
    }
}
