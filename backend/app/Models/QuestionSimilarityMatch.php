<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionSimilarityMatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'analysis_report_id',
        'current_question_id',
        'previous_question_id',
        'similarity_score',
        'similarity_status',
        'reasoning',
    ];

    protected function casts(): array
    {
        return [
            'similarity_score' => 'decimal:4',
        ];
    }

    /**
     * The analysis report this match belongs to.
     */
    public function analysisReport(): BelongsTo
    {
        return $this->belongsTo(AnalysisReport::class);
    }

    /**
     * The current assessment question.
     */
    public function currentQuestion(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'current_question_id');
    }

    /**
     * The historical previous question matched against.
     */
    public function previousQuestion(): BelongsTo
    {
        return $this->belongsTo(PreviousQuestion::class, 'previous_question_id');
    }
}

