<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cross-dimension question plan row: CO × Bloom × difficulty × type × marks × count. */
class AssessmentBlueprintItem extends Model
{
    protected $fillable = ['blueprint_id', 'section_id', 'topic', 'learning_outcome_id', 'program_outcome_id', 'question_type', 'difficulty_level', 'cognitive_level', 'question_count', 'marks_each', 'total_marks', 'sort_order'];

    protected function casts(): array
    {
        return ['question_count' => 'integer', 'marks_each' => 'float', 'total_marks' => 'float', 'sort_order' => 'integer'];
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(AssessmentBlueprint::class, 'blueprint_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(AssessmentBlueprintSection::class, 'section_id');
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
