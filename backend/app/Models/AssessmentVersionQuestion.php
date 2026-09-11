<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * STEP 38: historical snapshot of a question as it existed in one assessment version.
 * Later edits to the live question never change this row.
 */
class AssessmentVersionQuestion extends Model
{
    protected $fillable = [
        'assessment_version_id', 'original_question_id', 'question_number', 'section_name', 'question_text', 'question_type', 'marks',
        'difficulty_level', 'cognitive_level', 'topic', 'learning_outcome_id', 'program_outcome_id', 'expected_answer', 'rubric_snapshot', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['question_number' => 'integer', 'marks' => 'float', 'sort_order' => 'integer', 'rubric_snapshot' => 'array'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(AssessmentVersion::class, 'assessment_version_id');
    }

    public function originalQuestion(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'original_question_id');
    }

    public function learningOutcome(): BelongsTo
    {
        return $this->belongsTo(LearningOutcome::class);
    }

    public function programOutcome(): BelongsTo
    {
        return $this->belongsTo(ProgramOutcome::class);
    }

    public function studentAnswers(): HasMany
    {
        return $this->hasMany(StudentAnswer::class, 'assessment_version_question_id');
    }
}
