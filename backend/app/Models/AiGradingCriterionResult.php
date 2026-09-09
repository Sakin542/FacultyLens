<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * STEP 27: AI suggested marks and evidence for a single rubric criterion.
 */
class AiGradingCriterionResult extends Model
{
    use HasFactory;

    protected $table = 'ai_grading_criterion_results';

    protected $fillable = [
        'ai_grading_result_id',
        'rubric_criterion_id',
        'criterion',
        'suggested_marks',
        'maximum_marks',
        'evaluation',
        'evidence',
        'missing_elements',
        'coverage_level',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'suggested_marks' => 'decimal:2',
            'maximum_marks' => 'decimal:2',
            'evidence' => 'array',
            'missing_elements' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function gradingResult(): BelongsTo
    {
        return $this->belongsTo(AiGradingResult::class, 'ai_grading_result_id');
    }

    public function rubricCriterion(): BelongsTo
    {
        return $this->belongsTo(RubricCriterion::class);
    }
}
