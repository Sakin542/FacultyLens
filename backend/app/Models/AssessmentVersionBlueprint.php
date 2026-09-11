<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * STEP 38: normalized snapshot of the STEP 37 blueprint state used by one assessment version.
 * Distributions are stored as percentages so later blueprint edits never rewrite version history.
 */
class AssessmentVersionBlueprint extends Model
{
    protected $fillable = [
        'assessment_version_id', 'blueprint_id', 'blueprint_version', 'blueprint_status', 'validation_status', 'total_marks', 'question_count', 'duration_minutes',
        'difficulty_distribution', 'cognitive_distribution', 'learning_outcome_distribution', 'program_outcome_distribution', 'topic_distribution', 'question_type_distribution',
        'sections', 'constraints',
    ];

    protected function casts(): array
    {
        return [
            'blueprint_version' => 'integer', 'total_marks' => 'float', 'question_count' => 'integer', 'duration_minutes' => 'integer',
            'difficulty_distribution' => 'array', 'cognitive_distribution' => 'array', 'learning_outcome_distribution' => 'array', 'program_outcome_distribution' => 'array',
            'topic_distribution' => 'array', 'question_type_distribution' => 'array', 'sections' => 'array', 'constraints' => 'array',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(AssessmentVersion::class, 'assessment_version_id');
    }

    public function sourceBlueprint(): BelongsTo
    {
        return $this->belongsTo(AssessmentBlueprint::class, 'blueprint_id');
    }
}
