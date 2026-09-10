<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * STEP 33: An AI-drafted question awaiting faculty review. Never an official assessment question until
 * approved and explicitly added (see official_question_id).
 */
class GeneratedQuestion extends Model
{
    public const VALIDATION_PENDING = 'PENDING';
    public const VALIDATION_PASSED = 'PASSED';
    public const VALIDATION_WARNINGS = 'PASSED_WITH_WARNINGS';
    public const VALIDATION_FAILED = 'FAILED';

    public const REVIEW_DRAFT = 'DRAFT';
    public const REVIEW_REVIEWED = 'REVIEWED';
    public const REVIEW_APPROVED = 'APPROVED';
    public const REVIEW_REJECTED = 'REJECTED';

    protected $fillable = [
        'generation_request_id', 'sequence', 'question_text', 'original_question_text', 'question_type', 'marks',
        'difficulty_level', 'cognitive_level', 'learning_outcome_id', 'program_outcome_id', 'topic', 'options',
        'correct_option', 'expected_answer', 'explanation', 'source_chunk_ids', 'validation', 'validation_status',
        'review_status', 'review_note', 'version', 'edited_by', 'edited_at', 'approved_by', 'approved_at',
        'regenerated_from_id', 'official_question_id', 'added_to_assessment_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'marks' => 'float',
            'options' => 'array',
            'source_chunk_ids' => 'array',
            'validation' => 'array',
            'version' => 'integer',
            'edited_at' => 'datetime',
            'approved_at' => 'datetime',
            'added_to_assessment_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(QuestionGenerationRequest::class, 'generation_request_id');
    }

    public function learningOutcome(): BelongsTo
    {
        return $this->belongsTo(LearningOutcome::class);
    }

    public function programOutcome(): BelongsTo
    {
        return $this->belongsTo(ProgramOutcome::class);
    }

    public function officialQuestion(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'official_question_id');
    }

    public function regeneratedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'regenerated_from_id');
    }
}
