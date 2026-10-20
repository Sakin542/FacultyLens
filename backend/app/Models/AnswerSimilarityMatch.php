<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A pair of answers to the same question that look alike. A signal for faculty review, never a verdict. */
class AnswerSimilarityMatch extends Model
{
    protected $fillable = [
        'run_id', 'question_id', 'answer_a_id', 'answer_b_id', 'lexical_score', 'semantic_score', 'combined_score',
        'shared_excerpt', 'review_status', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'lexical_score' => 'float',
            'semantic_score' => 'float',
            'combined_score' => 'float',
            'reviewed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AnswerSimilarityRun::class, 'run_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function answerA(): BelongsTo
    {
        return $this->belongsTo(StudentAnswer::class, 'answer_a_id');
    }

    public function answerB(): BelongsTo
    {
        return $this->belongsTo(StudentAnswer::class, 'answer_b_id');
    }
}
