<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionLearningOutcomeAlignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'analysis_report_id',
        'question_id',
        'learning_outcome_id',
        'similarity_score',
        'alignment',
        'reasoning',
    ];

    protected function casts(): array
    {
        return [
            'similarity_score' => 'decimal:4',
        ];
    }

    /**
     * The analysis report this alignment belongs to.
     */
    public function analysisReport(): BelongsTo
    {
        return $this->belongsTo(AnalysisReport::class);
    }

    /**
     * The examination question.
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * The target course learning outcome.
     */
    public function learningOutcome(): BelongsTo
    {
        return $this->belongsTo(LearningOutcome::class);
    }
}

