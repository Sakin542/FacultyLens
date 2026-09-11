<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentBlueprintConstraint extends Model
{
    public const DIMENSIONS = ['DIFFICULTY', 'COGNITIVE_LEVEL', 'LEARNING_OUTCOME', 'PROGRAM_OUTCOME', 'TOPIC', 'QUESTION_TYPE'];
    public const TARGET_TYPES = ['percentage', 'count', 'marks'];

    protected $fillable = ['blueprint_id', 'dimension', 'target_type', 'target_key', 'learning_outcome_id', 'program_outcome_id', 'target_percentage', 'target_count', 'target_marks', 'metadata'];

    protected function casts(): array
    {
        return ['target_percentage' => 'float', 'target_count' => 'integer', 'target_marks' => 'float', 'metadata' => 'array'];
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(AssessmentBlueprint::class, 'blueprint_id');
    }

    public function learningOutcome(): BelongsTo
    {
        return $this->belongsTo(LearningOutcome::class);
    }

    public function programOutcome(): BelongsTo
    {
        return $this->belongsTo(ProgramOutcome::class);
    }
}
