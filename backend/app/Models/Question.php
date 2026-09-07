<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Question extends Model
{
    use HasFactory;

    protected $fillable = [
        'assessment_id',
        'question_number',
        'question_text',
        'question_type',
        'marks',
        'difficulty_level',
        'cognitive_level',
        'learning_outcome_id',
        'expected_answer',
    ];

    protected function casts(): array
    {
        return [
            'question_number' => 'integer',
            'marks' => 'decimal:2',
        ];
    }

    /**
     * The assessment this question belongs to.
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * The learning outcome mapped to this question.
     */
    public function learningOutcome(): BelongsTo
    {
        return $this->belongsTo(LearningOutcome::class);
    }
}

