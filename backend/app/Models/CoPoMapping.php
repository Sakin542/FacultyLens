<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * STEP 31: Faculty-defined CO (learning outcome) -> PO mapping with level 0..3.
 */
class CoPoMapping extends Model
{
    use HasFactory;

    public const LEVEL_NONE = 0;
    public const LEVEL_LOW = 1;
    public const LEVEL_MEDIUM = 2;
    public const LEVEL_HIGH = 3;
    public const LEVELS = [self::LEVEL_NONE, self::LEVEL_LOW, self::LEVEL_MEDIUM, self::LEVEL_HIGH];

    protected $fillable = ['course_id', 'learning_outcome_id', 'program_outcome_id', 'mapping_level', 'justification', 'created_by'];

    protected function casts(): array
    {
        return ['mapping_level' => 'integer'];
    }

    public static function levelLabel(int $level): string
    {
        return (string) (config('co_po.mapping_levels')[$level] ?? 'NONE');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
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
