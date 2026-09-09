<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** STEP 31: One persisted CO/PO mapping validation run (review signals, never an accreditation decision). */
class CoPoMappingAnalysisRun extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_STALE = 'STALE';

    protected $fillable = [
        'course_id', 'program_id', 'assessment_id', 'status', 'is_current', 'mapping_version',
        'summary', 'matrix', 'co_coverage', 'po_evidence', 'thresholds', 'error_message', 'requested_by', 'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'summary' => 'array',
            'matrix' => 'array',
            'co_coverage' => 'array',
            'po_evidence' => 'array',
            'thresholds' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function findings(): HasMany
    {
        // Severity ordering is applied in PHP (FIELD() is MySQL-only and breaks sqlite tests).
        return $this->hasMany(CoPoMappingFinding::class, 'analysis_run_id')->orderBy('id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_STALE], true);
    }
}
