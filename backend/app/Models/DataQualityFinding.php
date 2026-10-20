<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Read-only data-quality scan result. Findings point at records; the scanner never modifies them. */
class DataQualityFinding extends Model
{
    public const STATUSES = ['open', 'acknowledged', 'resolved', 'ignored'];

    protected $fillable = [
        'check', 'category', 'severity', 'entity_type', 'entity_id', 'entity_label', 'fingerprint', 'explanation',
        'remediation', 'status', 'auto_resolved', 'first_detected_at', 'last_detected_at', 'resolved_by', 'resolved_at',
        'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'auto_resolved' => 'boolean',
            'first_detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'check' => $this->check,
            'category' => $this->category,
            'severity' => $this->severity,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'entity_label' => $this->entity_label,
            'explanation' => $this->explanation,
            'remediation' => $this->remediation,
            'status' => $this->status,
            'auto_resolved' => $this->auto_resolved,
            'first_detected_at' => $this->first_detected_at?->toIso8601String(),
            'last_detected_at' => $this->last_detected_at?->toIso8601String(),
            'resolved_by' => $this->resolver?->only(['id', 'name']),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolution_note' => $this->resolution_note,
        ];
    }
}
