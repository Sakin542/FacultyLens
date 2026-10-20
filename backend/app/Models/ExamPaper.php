<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A composed exam paper for an assessment: question snapshots chosen from the course question bank,
 * existing course questions and approved AI drafts. Sets B/C derive from set A (shuffled and/or varied).
 */
class ExamPaper extends Model
{
    protected $fillable = [
        'assessment_id', 'created_by', 'blueprint_id', 'parent_paper_id', 'title', 'set_label', 'instructions',
        'duration_minutes', 'total_marks', 'status', 'build_mode', 'targets', 'coverage', 'shortfalls', 'seed',
        'finalized_at', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'total_marks' => 'float',
            'targets' => 'array',
            'coverage' => 'array',
            'shortfalls' => 'array',
            'finalized_at' => 'datetime',
            'applied_at' => 'datetime',
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_paper_id');
    }

    public function sets(): HasMany
    {
        return $this->hasMany(self::class, 'parent_paper_id')->orderBy('set_label');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExamPaperItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function isFinal(): bool
    {
        return $this->status === 'final';
    }
}
