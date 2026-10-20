<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceWindow extends Model
{
    protected $fillable = ['title', 'description', 'services', 'starts_at', 'ends_at', 'suppress_alerts', 'created_by', 'cancelled_at'];

    protected function casts(): array
    {
        return [
            'services' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'suppress_alerts' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActiveAt(Builder $query, $moment = null): Builder
    {
        $moment ??= now();

        return $query->whereNull('cancelled_at')->where('starts_at', '<=', $moment)->where('ends_at', '>=', $moment);
    }

    public function state(): string
    {
        if ($this->cancelled_at) {
            return 'cancelled';
        }
        if ($this->ends_at->isPast()) {
            return 'completed';
        }

        return $this->starts_at->isFuture() ? 'scheduled' : 'in_progress';
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'services' => $this->services ?? [],
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'suppress_alerts' => $this->suppress_alerts,
            'state' => $this->state(),
            'created_by' => $this->creator?->only(['id', 'name']),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
