<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** STEP 30: Mark-weighted performance for one learning outcome across its aligned questions. */
class LearningOutcomePerformanceResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'performance_analysis_run_id',
        'learning_outcome_id',
        'lo_code',
        'lo_description',
        'question_count',
        'question_ids',
        'response_count',
        'total_marks',
        'average_percentage',
        'performance_gap',
        'performance_status',
    ];

    protected function casts(): array
    {
        return [
            'question_count' => 'integer',
            'question_ids' => 'array',
            'response_count' => 'integer',
            'total_marks' => 'decimal:2',
            'average_percentage' => 'decimal:2',
            'performance_gap' => 'decimal:2',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PerformanceAnalysisRun::class, 'performance_analysis_run_id');
    }

    public function learningOutcome(): BelongsTo
    {
        return $this->belongsTo(LearningOutcome::class);
    }
}
