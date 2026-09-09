<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * STEP 27: One AI grading suggestion run for a student answer.
 *
 * suggested_marks is the AI recommendation only. The faculty's final decision is
 * stored on StudentAnswer (awarded_marks / faculty_feedback) and never overwrites this row.
 */
class AiGradingResult extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_REVIEWED = 'REVIEWED';
    public const STATUS_FINALIZED = 'FINALIZED';
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_REVIEWED,
        self::STATUS_FINALIZED,
    ];
    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_PROCESSING];

    public const DECISION_ACCEPTED = 'ACCEPTED';
    public const DECISION_MODIFIED = 'MODIFIED';
    public const DECISION_REJECTED = 'REJECTED';
    public const DECISIONS = [self::DECISION_ACCEPTED, self::DECISION_MODIFIED, self::DECISION_REJECTED];

    protected $fillable = [
        'student_answer_id',
        'student_submission_id',
        'question_id',
        'rubric_id',
        'rubric_version',
        'answer_fingerprint',
        'context_fingerprint',
        'suggested_marks',
        'maximum_marks',
        'overall_feedback',
        'strengths',
        'missing_elements',
        'evaluation_summary',
        'grading_status',
        'is_current',
        'faculty_decision',
        'error_message',
        'model_name',
        'model_version',
        'generation_method',
        'requested_by',
        'generated_at',
        'reviewed_at',
        'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'rubric_version' => 'integer',
            'suggested_marks' => 'decimal:2',
            'maximum_marks' => 'decimal:2',
            'strengths' => 'array',
            'missing_elements' => 'array',
            'is_current' => 'boolean',
            'generated_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function studentAnswer(): BelongsTo
    {
        return $this->belongsTo(StudentAnswer::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(StudentSubmission::class, 'student_submission_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(Rubric::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function criterionResults(): HasMany
    {
        return $this->hasMany(AiGradingCriterionResult::class)->orderBy('sort_order');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('grading_status', self::ACTIVE_STATUSES);
    }

    public function isActive(): bool
    {
        return in_array($this->grading_status, self::ACTIVE_STATUSES, true);
    }

    public function isCompleted(): bool
    {
        return in_array($this->grading_status, [self::STATUS_COMPLETED, self::STATUS_REVIEWED, self::STATUS_FINALIZED], true);
    }

    public function isFailed(): bool
    {
        return $this->grading_status === self::STATUS_FAILED;
    }
}
