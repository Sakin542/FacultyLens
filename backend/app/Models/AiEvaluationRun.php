<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiEvaluationRun extends Model
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_RUNNING = 'RUNNING';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public const GATE_PASSED = 'PASSED';
    public const GATE_WARNINGS = 'PASSED_WITH_WARNINGS';
    public const GATE_FAILED = 'FAILED';

    protected $fillable = [
        'dataset_id', 'task', 'model_id', 'prompt_version_id', 'configuration', 'status', 'gate_status', 'summary',
        'example_count', 'processed_count', 'inference_ms', 'failure_reason', 'started_at', 'completed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'summary' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime',
            'example_count' => 'integer', 'processed_count' => 'integer', 'inference_ms' => 'integer'];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(AiEvaluationDataset::class, 'dataset_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(AiPromptVersion::class, 'prompt_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function results(): HasMany
    {
        return $this->hasMany(AiEvaluationResult::class, 'evaluation_run_id');
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(AiEvaluationPrediction::class, 'evaluation_run_id');
    }

    /** @return array<string, float|null> */
    public function metricMap(): array
    {
        return $this->results->mapWithKeys(fn ($r) => [$r->metric_name => $r->metric_value === null ? null : (float) $r->metric_value])->all();
    }
}
