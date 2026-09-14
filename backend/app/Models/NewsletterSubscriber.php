<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class NewsletterSubscriber extends Model
{
    use Notifiable;

    protected $fillable = [
        'email', 'confirmation_token_hash', 'confirmation_sent_at', 'confirmed_at', 'unsubscribe_token', 'unsubscribed_at', 'source',
    ];

    protected $hidden = ['confirmation_token_hash', 'unsubscribe_token'];

    protected function casts(): array
    {
        return [
            'confirmation_sent_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null && $this->unsubscribed_at === null;
    }

    /** Confirmed and not unsubscribed — the audience of a dispatch. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('confirmed_at')->whereNull('unsubscribed_at');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
