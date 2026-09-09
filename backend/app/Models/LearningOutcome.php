<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LearningOutcome extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'code',
        'description',
        'cognitive_level',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * The course this learning outcome belongs to.
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Questions mapped to this learning outcome.
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    /** STEP 31: CO -> PO mappings for this outcome (acting as a Course Outcome). */
    public function poMappings(): HasMany
    {
        return $this->hasMany(CoPoMapping::class);
    }

    public function questionCoMappings(): HasMany
    {
        return $this->hasMany(QuestionCoMapping::class);
    }

    protected static function booted(): void
    {
        $invalidate = fn (LearningOutcome $lo) => \App\Services\CoPoMappingValidatorService::invalidateCache((int) $lo->course_id);
        static::saved($invalidate);
        static::deleted($invalidate);
    }
}

