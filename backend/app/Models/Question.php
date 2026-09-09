<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Question extends Model
{
    use HasFactory;

    protected $fillable = [
        'assessment_id',
        'question_number',
        'question_text',
        'question_type',
        'marks',
        'difficulty_level',
        'cognitive_level',
        'learning_outcome_id',
        'expected_answer',
        'ai_question_type',
        'ai_difficulty_level',
        'ai_cognitive_level',
        'ai_topics',
        'ai_analysis_status',
        'ai_analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'question_number' => 'integer',
            'marks' => 'decimal:2',
            'ai_topics' => 'array',
            'ai_analyzed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // STEP 30/31: marks / LO / topic changes invalidate cached performance and CO/PO analytics.
        $invalidate = function (Question $q) {
            \App\Services\StudentPerformanceService::invalidateCache((int) $q->assessment_id);
            \App\Services\CoPoMappingValidatorService::invalidateCacheForAssessment((int) $q->assessment_id);
        };
        static::saved($invalidate);
        static::deleted($invalidate);
    }

    public function coMappings(): HasMany
    {
        return $this->hasMany(QuestionCoMapping::class);
    }

    /**
     * The assessment this question belongs to.
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * The learning outcome mapped to this question.
     */
    public function learningOutcome(): BelongsTo
    {
        return $this->belongsTo(LearningOutcome::class);
    }

    /**
     * STEP 25: All rubric versions generated for this question (newest first).
     */
    public function rubrics(): HasMany
    {
        return $this->hasMany(Rubric::class)->orderByDesc('version');
    }

    /**
     * The currently approved rubric for this question, if any.
     */
    public function approvedRubric(): HasOne
    {
        return $this->hasOne(Rubric::class)->where('status', Rubric::STATUS_APPROVED)->latestOfMany('version');
    }

    /**
     * STEP 26: Student answers to this question across all submissions.
     */
    public function studentAnswers(): HasMany
    {
        return $this->hasMany(StudentAnswer::class);
    }
}

