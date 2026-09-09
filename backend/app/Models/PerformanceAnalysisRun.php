<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * STEP 30: One snapshot of student performance / gap analysis for an assessment.
 * Built exclusively from finalized faculty marks; provides review signals, not decisions.
 */
class PerformanceAnalysisRun extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_STALE = 'STALE';
    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_PROCESSING];

    public const PERF_STRONG = 'STRONG';
    public const PERF_ON_TARGET = 'ON_TARGET';
    public const PERF_MINOR_GAP = 'MINOR_GAP';
    public const PERF_MODERATE_GAP = 'MODERATE_GAP';
    public const PERF_HIGH_GAP = 'HIGH_GAP';
    public const PERF_INSUFFICIENT = 'INSUFFICIENT_DATA';
    public const PERFORMANCE_STATUSES = [
        self::PERF_STRONG,
        self::PERF_ON_TARGET,
        self::PERF_MINOR_GAP,
        self::PERF_MODERATE_GAP,
        self::PERF_HIGH_GAP,
        self::PERF_INSUFFICIENT,
    ];
    public const GAP_STATUSES = [self::PERF_MINOR_GAP, self::PERF_MODERATE_GAP, self::PERF_HIGH_GAP];

    protected $fillable = [
        'assessment_id',
        'course_id',
        'expected_performance_percent',
        'minimum_responses',
        'thresholds',
        'status',
        'is_current',
        'grading_fingerprint',
        'student_count',
        'submission_count',
        'finalized_answer_count',
        'question_count',
        'overall_average_percentage',
        'overall_gap',
        'overall_status',
        'summary',
        'error_message',
        'requested_by',
        'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_performance_percent' => 'decimal:2',
            'minimum_responses' => 'integer',
            'thresholds' => 'array',
            'is_current' => 'boolean',
            'student_count' => 'integer',
            'submission_count' => 'integer',
            'finalized_answer_count' => 'integer',
            'question_count' => 'integer',
            'overall_average_percentage' => 'decimal:2',
            'overall_gap' => 'decimal:2',
            'summary' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function questionResults(): HasMany
    {
        return $this->hasMany(QuestionPerformanceResult::class)->orderBy('question_number');
    }

    public function topicResults(): HasMany
    {
        return $this->hasMany(TopicPerformanceResult::class)->orderBy('average_percentage');
    }

    public function learningOutcomeResults(): HasMany
    {
        return $this->hasMany(LearningOutcomePerformanceResult::class)->orderBy('lo_code');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_STALE], true);
    }
}
