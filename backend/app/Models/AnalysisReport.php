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

    // ------------------------------------------------------------------
    // Run lifecycle (STEP 42, BUG-001/002). Completed reports are immutable
    // history: a new run always gets its own row, and a failure only ever
    // marks the attempt that failed.
    // ------------------------------------------------------------------

    /**
     * Columns needed by the run-lifecycle lookups. `findings` can exceed 100 KB per row; selecting it in an
     * ORDER BY … LIMIT 1 lookup made MySQL 8 fail with error 1038 "Out of sort memory" under concurrent load
     * (STEP 43 load test, 10+ parallel analysis requests).
     */
    protected const LIFECYCLE_COLUMNS = ['id', 'assessment_id', 'analysis_version', 'analysis_status', 'is_current', 'processing_error', 'updated_at'];

    /**
     * The report that represents the assessment's current analysis state:
     * the current completed report, else the most recent attempt.
     */
    public static function currentFor(int $assessmentId): ?self
    {
        return static::where('assessment_id', $assessmentId)->where('is_current', true)->orderByDesc('id')->first()
            ?? static::where('assessment_id', $assessmentId)->orderByDesc('id')->first();
    }

    /**
     * Return a row in the `processing` state for a new run without touching completed history.
     */
    public static function beginRun(int $assessmentId): self
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($assessmentId) {
            $latest = static::where('assessment_id', $assessmentId)->orderByDesc('id')->lockForUpdate()->first(self::LIFECYCLE_COLUMNS);

            if ($latest && $latest->analysis_status !== 'completed') {
                $latest->forceFill(['analysis_status' => 'processing', 'processing_error' => null])->save();

                return $latest;
            }

            $maxVersion = (int) static::where('assessment_id', $assessmentId)->max('analysis_version');

            return static::create([
                'assessment_id' => $assessmentId,
                'analysis_version' => $maxVersion + 1,
                'is_current' => $latest === null,
                'analysis_status' => 'processing',
                'processing_error' => null,
            ]);
        });
    }

    /**
     * Persist the results of this run and promote it to the current completed report.
     */
    public function completeRun(array $attributes): self
    {
        static::where('assessment_id', $this->assessment_id)->where('id', '!=', $this->id)->update(['is_current' => false]);

        $this->forceFill(array_merge($attributes, [
            'is_current' => true,
            'analysis_status' => 'completed',
            'processing_error' => null,
            'analyzed_at' => $attributes['analyzed_at'] ?? now(),
        ]))->save();

        return $this;
    }

    public function failRun(string $error): self
    {
        $this->forceFill(['analysis_status' => 'failed', 'processing_error' => $error])->save();

        return $this;
    }

    /**
     * Record a failure when the run handle is not available (e.g. before beginRun succeeded).
     * Never downgrades a completed report.
     */
    public static function recordFailure(int $assessmentId, string $error): self
    {
        $latest = static::where('assessment_id', $assessmentId)->orderByDesc('id')->first(self::LIFECYCLE_COLUMNS);

        if ($latest && $latest->analysis_status !== 'completed') {
            return $latest->failRun($error);
        }

        return static::create([
            'assessment_id' => $assessmentId,
            'analysis_version' => (int) static::where('assessment_id', $assessmentId)->max('analysis_version') + 1,
            'is_current' => $latest === null,
            'analysis_status' => 'failed',
            'processing_error' => $error,
        ]);
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

