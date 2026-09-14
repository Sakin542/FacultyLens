<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One outbound e-mail. Status lifecycle: PENDING → PROCESSING → SENT | FAILED (permanent) | CANCELLED.
 * Holds metadata only — the rendered body and SMTP credentials are never stored.
 */
class EmailDelivery extends Model
{
    use HasFactory;

    public const PENDING = 'PENDING';
    public const PROCESSING = 'PROCESSING';
    public const SENT = 'SENT';
    public const FAILED = 'FAILED';
    public const CANCELLED = 'CANCELLED';

    public const STATUSES = [self::PENDING, self::PROCESSING, self::SENT, self::FAILED, self::CANCELLED];

    protected $fillable = [
        'user_id', 'notification_id', 'type', 'category', 'template', 'recipient', 'subject', 'status', 'provider',
        'idempotency_key', 'attempts', 'message_id', 'queued_at', 'sent_at', 'failed_at', 'error_code', 'error_message',
    ];

    protected $hidden = ['idempotency_key'];

    protected $casts = [
        'user_id' => 'integer',
        'attempts' => 'integer',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::SENT, self::FAILED, self::CANCELLED], true);
    }

    /** Safe API representation: recipient is masked, no error internals beyond a category code. */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'category' => $this->category,
            'template' => $this->template,
            'recipient' => self::maskAddress($this->recipient),
            'subject' => $this->subject,
            'status' => $this->status,
            'provider' => $this->provider,
            'attempts' => $this->attempts,
            'notification_id' => $this->notification_id,
            'queued_at' => $this->queued_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'error_code' => $this->error_code,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public static function maskAddress(?string $email): ?string
    {
        if ($email === null || !str_contains($email, '@')) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible . str_repeat('*', max(1, mb_strlen($local) - mb_strlen($visible))) . '@' . $domain;
    }
}
