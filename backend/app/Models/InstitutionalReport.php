<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * STEP 39: a requested/generated institutional report. Filters and the assessment version are
 * snapshotted so a historical report always traces back to its original configuration.
 */
class InstitutionalReport extends Model
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_PROCESSING = 'PROCESSING';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'report_uuid', 'created_by', 'report_type', 'scope_type', 'course_id', 'assessment_id', 'assessment_version_id', 'department', 'program_id',
        'filters', 'title', 'format', 'status', 'is_async', 'contains_student_data', 'file_path', 'file_name', 'file_size', 'record_count', 'summary',
        'data_as_of', 'started_at', 'generated_at', 'expires_at', 'file_deleted_at', 'error_message',
    ];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'summary' => 'array',
            'is_async' => 'boolean',
            'contains_student_data' => 'boolean',
            'file_size' => 'integer',
            'record_count' => 'integer',
            'data_as_of' => 'datetime',
            'started_at' => 'datetime',
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
            'file_deleted_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function assessmentVersion(): BelongsTo
    {
        return $this->belongsTo(AssessmentVersion::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_COMPLETED && $this->file_path !== null && $this->file_deleted_at === null && ! $this->isExpired();
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED], true);
    }
}
