<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recommendation extends Model
{
    use HasFactory;

    protected $fillable = [
        'analysis_report_id',
        'category',
        'problem',
        'title',
        'description',
        'explanation',
        'recommendation',
        'evidence',
        'source_metric',
        'priority',
        'status',
        'faculty_notes',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
        ];
    }

    /**
     * The analysis report this recommendation belongs to.
     */
    public function analysisReport(): BelongsTo
    {
        return $this->belongsTo(AnalysisReport::class);
    }

    /**
     * Feedback submissions for this recommendation.
     */
    public function feedback(): HasMany
    {
        return $this->hasMany(RecommendationFeedback::class)->latest();
    }

    /**
     * Status transition audit history for this recommendation.
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(RecommendationDecision::class)->latest();
    }

    /**
     * AI improvement signals derived from decisions on this recommendation.
     */
    public function improvementSignals(): HasMany
    {
        return $this->hasMany(AiImprovementSignal::class)->latest();
    }
}
