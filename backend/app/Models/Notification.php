<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\DatabaseNotification;

/**
 * STEP 47: FacultyLens in-app notification. Rows live in Laravel's `notifications` table (uuid primary key) extended
 * with typed columns. `data` only ever holds non-sensitive identifiers (assessment_id, report_id, …) — never secrets,
 * student answers or document content (enforced by NotificationService).
 */
class Notification extends DatabaseNotification
{
    use HasFactory;

    protected $table = 'notifications';

    protected $guarded = [];

    protected $hidden = ['notifiable_type', 'notifiable_id', 'dedupe_key'];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'expires_at' => 'datetime',
        'entity_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ------------------------------------------------------------------ state

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    public function isDismissed(): bool
    {
        return $this->dismissed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function markAsDismissed(): void
    {
        if ($this->dismissed_at === null) {
            $this->forceFill(['dismissed_at' => $this->freshTimestamp()])->save();
        }
    }

    // ---------------------------------------------------------------- scopes

    /** Not dismissed and not expired: what the bell and the list show. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }

    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', strtoupper($category));
    }

    // ------------------------------------------------------------- serialise

    /** API shape shared by the list, the dropdown and the mark-read responses. */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'category' => $this->category,
            'severity' => $this->severity,
            'title' => $this->title,
            'message' => $this->message,
            'data' => $this->data ?? [],
            'action_url' => $this->action_url,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'read_at' => $this->read_at?->toIso8601String(),
            'dismissed_at' => $this->dismissed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
