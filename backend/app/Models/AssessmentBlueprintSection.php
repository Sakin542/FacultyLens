<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentBlueprintSection extends Model
{
    protected $fillable = ['blueprint_id', 'title', 'section_order', 'instructions', 'question_type', 'question_count', 'marks_per_question', 'total_marks', 'difficulty_distribution', 'cognitive_distribution'];

    protected function casts(): array
    {
        return ['section_order' => 'integer', 'question_count' => 'integer', 'marks_per_question' => 'float', 'total_marks' => 'float', 'difficulty_distribution' => 'array', 'cognitive_distribution' => 'array'];
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(AssessmentBlueprint::class, 'blueprint_id');
    }
}
