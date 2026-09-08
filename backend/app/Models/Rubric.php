<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * STEP 25: AI-generated draft grading rubric for a single assessment question.
 * Faculty review, edit and approve. Regeneration creates a new version; old versions remain readable.
 */
class Rubric extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_ARCHIVED = 'ARCHIVED';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_APPROVED,
        self::STATUS_ARCHIVED,
    ];

    protected $fillable = [
        'question_id',
        'assessment_id',
        'created_by',
        'title',
        'total_marks',
        'status',
        'version',
        'generation_method',
        'ai_model',
        'ai_model_version',
        'general_guidance',
        'generated_at',
        'approved_at',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'total_marks' => 'decimal:2',
            'version' => 'integer',
            'generated_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    protected $appends = ['criteria_total', 'is_ai_generated'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(RubricCriterion::class)->orderBy('sort_order');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    /**
     * Sum of criterion marks (uses loaded relation when available to avoid extra queries).
     */
    public function getCriteriaTotalAttribute(): float
    {
        $criteria = $this->relationLoaded('criteria') ? $this->criteria : $this->criteria()->get();

        return round((float) $criteria->sum(fn ($c) => (float) $c->max_marks), 2);
    }

    public function getIsAiGeneratedAttribute(): bool
    {
        return $this->generation_method !== 'manual';
    }
}
