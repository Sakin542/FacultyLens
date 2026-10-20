<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A dated item on a faculty member's academic calendar (exam, deadline, grading deadline, meeting...). */
class AcademicEvent extends Model
{
    public const TYPES = ['exam', 'quiz', 'assignment_deadline', 'grading_deadline', 'class', 'meeting', 'other'];

    protected $fillable = [
        'user_id', 'course_id', 'assessment_id', 'title', 'type', 'starts_at', 'ends_at', 'all_day', 'location', 'notes',
        'reminder_offsets', 'reminders_sent',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
            'reminder_offsets' => 'array',
            'reminders_sent' => 'array',
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

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }
}
