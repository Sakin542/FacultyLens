<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A reusable grading comment in a faculty member's personal comment bank. */
class FeedbackComment extends Model
{
    public const TONES = ['positive', 'improvement', 'neutral'];

    protected $fillable = ['user_id', 'course_id', 'criterion', 'title', 'body', 'tone', 'usage_count', 'last_used_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'usage_count' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'course' => $this->course ? ['id' => $this->course->id, 'course_code' => $this->course->course_code] : null,
            'criterion' => $this->criterion,
            'title' => $this->title,
            'body' => $this->body,
            'tone' => $this->tone,
            'usage_count' => $this->usage_count,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
        ];
    }
}
