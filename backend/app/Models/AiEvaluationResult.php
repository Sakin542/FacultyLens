<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiEvaluationResult extends Model
{
    protected $fillable = ['evaluation_run_id', 'metric_name', 'metric_value', 'metric_metadata'];

    protected function casts(): array
    {
        return ['metric_metadata' => 'array', 'metric_value' => 'float'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiEvaluationRun::class, 'evaluation_run_id');
    }
}
