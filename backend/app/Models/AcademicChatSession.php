<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicChatSession extends Model
{
    public const SCOPE_COURSE = 'COURSE';
    public const SCOPE_DOCUMENT = 'DOCUMENT';
    public const SCOPE_ASSESSMENT = 'ASSESSMENT';

    public const SCOPES = [self::SCOPE_COURSE, self::SCOPE_DOCUMENT, self::SCOPE_ASSESSMENT];

    protected $fillable = [
        'user_id',
        'scope_type',
        'course_id',
        'document_processing_id',
        'assessment_id',
        'title',
        'status',
        'message_count',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'message_count' => 'integer',
            'last_message_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(DocumentProcessing::class, 'document_processing_id');
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AcademicChatMessage::class)->orderBy('id');
    }
}
