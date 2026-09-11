<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnalysisReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'assessment_id',
        'assessment_version_id',
        'version_content_hash',
        'analysis_version',
        'overall_score',
        'topic_coverage_score',
        'learning_outcome_alignment_score',
        'difficulty_balance_score',
        'cognitive_level_balance_score',
        'similarity_score',
        'total_questions',
        'similar_questions_count',
        'findings',
        'analysis_status',
        'is_current',
        'processing_error',
        'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'analysis_version' => 'integer',
            'is_current' => 'boolean',
            'overall_score' => 'decimal:2',
            'topic_coverage_score' => 'decimal:2',
            'learning_outcome_alignment_score' => 'decimal:2',
            'difficulty_balance_score' => 'decimal:2',
            'cognitive_level_balance_score' => 'decimal:2',
            'similarity_score' => 'decimal:2',
            'total_questions' => 'integer',
            'similar_questions_count' => 'integer',
            'findings' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // STEP 38: a completed analysis is attached to the assessment version whose snapshot it describes (all persistence paths).
        static::saved(function (AnalysisReport $report) {
            if ($report->analysis_status === 'completed' && $report->version_content_hash === null) {
                app(\App\Services\AssessmentVersionService::class)->linkAnalysisReport($report);
            }
        });
    }

    /**
     * Scope query to only include completed analyses.
     */
    public function scopeCompleted($query)
    {
        return $query->where('analysis_status', 'completed');
    }

    /**
     * Scope query to only include current active version for each assessment.
     */
    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    /**
     * The assessment this analysis report was generated for.
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * STEP 38: The assessment version whose state this analysis describes (null for pre-versioning reports).
     */
    public function assessmentVersion(): BelongsTo
    {
        return $this->belongsTo(AssessmentVersion::class);
    }

    /**
     * The AI recommendations generated from this analysis report.
     */
    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class);
    }

    /**
     * The question similarity matches recorded for this analysis report.
     */
    public function similarityMatches(): HasMany
    {
        return $this->hasMany(QuestionSimilarityMatch::class);
    }

    /**
     * The learning outcome alignment records for this analysis report.
     */
    public function learningOutcomeAlignments(): HasMany
    {
        return $this->hasMany(QuestionLearningOutcomeAlignment::class);
    }

    /**
     * The generated assessment reports (PDF/exports) for this analysis.
     */
    public function assessmentReports(): HasMany
    {
        return $this->hasMany(AssessmentReport::class);
    }
}

