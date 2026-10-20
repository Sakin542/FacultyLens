<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One outbound call to the FastAPI AI service. Metadata only: no prompt, document or answer text is stored.
 */
class AiUsageLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'operation', 'endpoint', 'status_code', 'success', 'duration_ms',
        'request_bytes', 'response_bytes', 'estimated_tokens', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
