<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * STEP 38: one immutable-once-finalized state of an assessment (metadata + question / blueprint / rubric snapshots).
 */
class AssessmentVersion extends Model
{
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_IN_REVIEW = 'IN_REVIEW';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_FINALIZED = 'FINALIZED';
    public const STATUS_ARCHIVED = 'ARCHIVED';
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_IN_REVIEW, self::STATUS_APPROVED, self::STATUS_FINALIZED, self::STATUS_ARCHIVED];

    public const TYPE_MAJOR = 'MAJOR';
    public const TYPE_MINOR = 'MINOR';
    public const TYPES = [self::TYPE_MAJOR, self::TYPE_MINOR];

    /** Statuses whose content may still change (edits reset IN_REVIEW to DRAFT). */
    public const EDITABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_IN_REVIEW];

    protected $fillable = [
        'assessment_id', 'version_number', 'version_label', 'version_type', 'status', 'title', 'description', 'instructions', 'assessment_type',
        'total_marks', 'duration_minutes', 'question_count', 'created_by', 'based_on_version_id', 'change_summary', 'content_hash',
        'validation_status', 'validation_result', 'validated_at', 'submitted_at', 'approved_at', 'approved_by', 'finalized_at', 'finalized_by', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer', 'total_marks' => 'float', 'duration_minutes' => 'integer', 'question_count' => 'integer',
            'validation_result' => 'array', 'validated_at' => 'datetime', 'submitted_at' => 'datetime', 'approved_at' => 'datetime',
            'finalized_at' => 'datetime', 'archived_at' => 'datetime',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(AssessmentVersion::class, 'based_on_version_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(AssessmentVersionQuestion::class)->orderBy('sort_order')->orderBy('question_number');
    }

    public function blueprint(): HasOne
    {
        return $this->hasOne(AssessmentVersionBlueprint::class);
    }

    public function analysisReports(): HasMany
    {
        return $this->hasMany(AnalysisReport::class)->orderByDesc('analysis_version');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(StudentSubmission::class);
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    public function isEditableStatus(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true);
    }

    public function hasSubmissions(): bool
    {
        return $this->submissions()->exists();
    }

    /** Content is locked once finalized/archived/approved or once any student submission references this version. */
    public function isLocked(): bool
    {
        return !$this->isEditableStatus() || $this->hasSubmissions();
    }
}
