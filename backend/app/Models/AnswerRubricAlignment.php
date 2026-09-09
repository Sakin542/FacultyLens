<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * STEP 28: One Answer <-> Rubric alignment analysis run.
 * Alignment describes rubric coverage evidence; it is neither a grade nor a correctness verdict.
 */
class AnswerRubricAlignment extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_REVIEWED = 'REVIEWED';
    public const STATUS_STALE = 'STALE';
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_REVIEWED,
        self::STATUS_STALE,
    ];
    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_PROCESSING];

    public const ALIGN_STRONG = 'STRONG';
    public const ALIGN_PARTIAL = 'PARTIAL';
    public const ALIGN_WEAK = 'WEAK';
    public const ALIGN_NOT_ALIGNED = 'NOT_ALIGNED';
    public const ALIGNMENT_STATUSES = [self::ALIGN_STRONG, self::ALIGN_PARTIAL, self::ALIGN_WEAK, self::ALIGN_NOT_ALIGNED];
    public const ALIGNMENT_WEIGHTS = [
        self::ALIGN_STRONG => 1.0,
        self::ALIGN_PARTIAL => 0.5,
        self::ALIGN_WEAK => 0.25,
        self::ALIGN_NOT_ALIGNED => 0.0,
    ];

    protected $fillable = [
        'student_answer_id',
        'student_submission_id',
        'question_id',
        'rubric_id',
        'rubric_version',
        'answer_fingerprint',
        'context_fingerprint',
        'overall_alignment_score',
        'unweighted_alignment_score',
        'alignment_status',
        'analysis_status',
        'is_current',
        'counts',
        'summary',
        'strengths',
        'missing_elements',
        'error_message',
        'model_name',
        'model_version',
        'analysis_method',
        'thresholds',
        'requested_by',
        'generated_at',
        'reviewed_at',
        'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'rubric_version' => 'integer',
            'overall_alignment_score' => 'decimal:2',
            'unweighted_alignment_score' => 'decimal:2',
            'is_current' => 'boolean',
            'counts' => 'array',
            'strengths' => 'array',
            'missing_elements' => 'array',
            'thresholds' => 'array',
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

    public function criterionAlignments(): HasMany
    {
        return $this->hasMany(AnswerRubricCriterionAlignment::class)->orderBy('sort_order');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('analysis_status', self::ACTIVE_STATUSES);
    }

    public function isActive(): bool
    {
        return in_array($this->analysis_status, self::ACTIVE_STATUSES, true);
    }

    public function isCompleted(): bool
    {
        return in_array($this->analysis_status, [self::STATUS_COMPLETED, self::STATUS_REVIEWED, self::STATUS_STALE], true);
    }
}
