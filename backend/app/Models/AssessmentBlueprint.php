<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * STEP 37: versioned assessment blueprint (planning layer; never publishes or finalizes the assessment itself).
 */
class AssessmentBlueprint extends Model
{
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_VALIDATED = 'VALIDATED';
    public const STATUS_FINALIZED = 'FINALIZED';
    public const STATUS_ARCHIVED = 'ARCHIVED';
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_VALIDATED, self::STATUS_FINALIZED, self::STATUS_ARCHIVED];

    public const VALID = 'VALID';
    public const VALID_WITH_WARNINGS = 'VALID_WITH_WARNINGS';
    public const INVALID = 'INVALID';

    protected $fillable = [
        'assessment_id', 'created_by', 'version', 'status', 'is_current', 'title', 'total_marks', 'total_questions', 'duration_minutes', 'instructions',
        'validation_status', 'validation_result', 'blueprint_completeness', 'validated_at', 'finalized_at', 'finalized_by',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean', 'total_marks' => 'float', 'total_questions' => 'integer', 'duration_minutes' => 'integer', 'version' => 'integer',
            'validation_result' => 'array', 'blueprint_completeness' => 'float', 'validated_at' => 'datetime', 'finalized_at' => 'datetime',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(AssessmentBlueprintSection::class, 'blueprint_id')->orderBy('section_order');
    }

    public function constraints(): HasMany
    {
        return $this->hasMany(AssessmentBlueprintConstraint::class, 'blueprint_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AssessmentBlueprintItem::class, 'blueprint_id')->orderBy('sort_order');
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_VALIDATED], true);
    }
}
