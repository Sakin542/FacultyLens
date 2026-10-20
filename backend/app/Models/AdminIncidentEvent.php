<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only incident timeline entry. */
class AdminIncidentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['incident_id', 'user_id', 'type', 'body', 'metadata', 'created_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'body' => $this->body,
            'metadata' => $this->metadata,
            'user' => $this->user?->only(['id', 'name']),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
