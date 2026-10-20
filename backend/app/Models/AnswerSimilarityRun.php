<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One plagiarism check over an assessment's student answers. */
class AnswerSimilarityRun extends Model
{
    protected $fillable = [
        'assessment_id', 'run_by', 'status', 'method', 'threshold', 'answers_compared', 'pairs_compared', 'pairs_flagged', 'summary',
    ];

    protected function casts(): array
    {
        return ['threshold' => 'float', 'summary' => 'array'];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(AnswerSimilarityMatch::class, 'run_id')->orderByDesc('combined_score');
    }
}
