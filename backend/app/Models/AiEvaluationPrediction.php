<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiEvaluationPrediction extends Model
{
    protected $fillable = ['evaluation_run_id', 'example_id', 'prediction', 'expected_output', 'is_correct', 'score', 'error_type', 'metadata'];

    protected function casts(): array
    {
        return ['prediction' => 'array', 'expected_output' => 'array', 'metadata' => 'array', 'is_correct' => 'boolean', 'score' => 'float'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiEvaluationRun::class, 'evaluation_run_id');
    }

    public function example(): BelongsTo
    {
        return $this->belongsTo(AiEvaluationExample::class, 'example_id');
    }
}
