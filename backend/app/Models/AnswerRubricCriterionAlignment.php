<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * STEP 28: Alignment of a student answer with a single rubric criterion.
 */
class AnswerRubricCriterionAlignment extends Model
{
    use HasFactory;

    protected $table = 'answer_rubric_criterion_alignments';

    protected $fillable = [
        'answer_rubric_alignment_id',
        'rubric_criterion_id',
        'criterion',
        'max_marks',
        'alignment_score',
        'similarity',
        'alignment_status',
        'evidence',
        'missing_elements',
        'explanation',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'max_marks' => 'decimal:2',
            'alignment_score' => 'decimal:2',
            'similarity' => 'decimal:4',
            'evidence' => 'array',
            'missing_elements' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function alignment(): BelongsTo
    {
        return $this->belongsTo(AnswerRubricAlignment::class, 'answer_rubric_alignment_id');
    }

    public function rubricCriterion(): BelongsTo
    {
        return $this->belongsTo(RubricCriterion::class);
    }
}
