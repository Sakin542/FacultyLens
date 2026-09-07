<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'assessment_id',
        'analysis_report_id',
        'report_uuid',
        'file_name',
        'file_path',
        'file_size',
        'generation_status',
        'is_shareable',
        'share_token',
        'shared_at',
        'revoked_at',
        'generated_at',
        'created_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_shareable' => 'boolean',
            'shared_at' => 'datetime',
            'revoked_at' => 'datetime',
            'generated_at' => 'datetime',
            'metadata' => 'array',
            'file_size' => 'integer',
        ];
    }

    /**
     * The assessment this report belongs to.
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * The underlying AI analysis report.
     */
    public function analysisReport(): BelongsTo
    {
        return $this->belongsTo(AnalysisReport::class);
    }

    /**
     * The faculty user who generated or owns this report.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Check if share link is currently valid and active.
     */
    public function isShareActive(): bool
    {
        return $this->is_shareable && !empty($this->share_token) && ($this->revoked_at === null || $this->revoked_at->isFuture());
    }
}

