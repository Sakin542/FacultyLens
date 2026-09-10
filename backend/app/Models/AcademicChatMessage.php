<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicChatMessage extends Model
{
    public const ROLE_USER = 'USER';
    public const ROLE_ASSISTANT = 'ASSISTANT';

    protected $fillable = [
        'academic_chat_session_id',
        'role',
        'content',
        'grounded',
        'generation_method',
        'generation_model',
        'embedding_model',
        'prompt_version',
        'retrieval_metadata',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'grounded' => 'boolean',
            'retrieval_metadata' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicChatSession::class, 'academic_chat_session_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(AcademicChatSource::class)->orderBy('source_order');
    }
}
