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
        'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
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

    /**
     * The assessment this analysis report was generated for.
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * The AI recommendations generated from this analysis report.
     */
    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class);
    }
}

