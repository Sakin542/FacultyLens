<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * STEP 26: A student's submission for one assessment. Holds answers per question.
 */
class StudentSubmission extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_SUBMITTED = 'SUBMITTED';
    public const STATUS_UNDER_REVIEW = 'UNDER_REVIEW';
    public const STATUS_GRADED = 'GRADED';
    public const STATUS_RETURNED = 'RETURNED';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_GRADED,
        self::STATUS_RETURNED,
    ];

    /** Allowed forward/backward transitions. RETURNED is terminal. */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_SUBMITTED],
        self::STATUS_SUBMITTED => [self::STATUS_UNDER_REVIEW, self::STATUS_DRAFT],
        self::STATUS_UNDER_REVIEW => [self::STATUS_GRADED, self::STATUS_SUBMITTED],
        self::STATUS_GRADED => [self::STATUS_RETURNED, self::STATUS_UNDER_REVIEW],
        self::STATUS_RETURNED => [],
    ];

    public const GRADING_NOT_STARTED = 'NOT_STARTED';
    public const GRADING_IN_PROGRESS = 'IN_PROGRESS';
    public const GRADING_AI_ASSISTED = 'AI_ASSISTED';
    public const GRADING_FACULTY_REVIEWED = 'FACULTY_REVIEWED';
    public const GRADING_FINALIZED = 'FINALIZED';

    public const GRADING_STATUSES = [
        self::GRADING_NOT_STARTED,
        self::GRADING_IN_PROGRESS,
        self::GRADING_AI_ASSISTED,
        self::GRADING_FACULTY_REVIEWED,
        self::GRADING_FINALIZED,
    ];

    protected $fillable = [
        'assessment_id',
        'student_id',
        'submission_identifier',
        'submitted_at',
        'status',
        'grading_status',
        'total_marks',
        'awarded_marks',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'total_marks' => 'decimal:2',
            'awarded_marks' => 'decimal:2',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(StudentAnswer::class);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }
}
