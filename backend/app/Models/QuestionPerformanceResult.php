<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** STEP 30: Aggregate performance for one question (finalized marks only). */
class QuestionPerformanceResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'performance_analysis_run_id',
        'question_id',
        'question_number',
        'question_text_excerpt',
        'maximum_marks',
        'response_count',
        'submission_count',
        'average_marks',
        'average_percentage',
        'median_marks',
        'minimum_marks',
        'max_awarded_marks',
        'performance_gap',
        'performance_status',
        'difficulty_level',
        'cognitive_level',
        'topics',
        'ai_suggested_average_percentage',
        'rubric_alignment_average',
        'review_signals',
    ];

    protected function casts(): array
    {
        return [
            'question_number' => 'integer',
            'maximum_marks' => 'decimal:2',
            'response_count' => 'integer',
            'submission_count' => 'integer',
            'average_marks' => 'decimal:2',
            'average_percentage' => 'decimal:2',
            'median_marks' => 'decimal:2',
            'minimum_marks' => 'decimal:2',
            'max_awarded_marks' => 'decimal:2',
            'performance_gap' => 'decimal:2',
            'topics' => 'array',
            'ai_suggested_average_percentage' => 'decimal:2',
            'rubric_alignment_average' => 'decimal:2',
            'review_signals' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PerformanceAnalysisRun::class, 'performance_analysis_run_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
