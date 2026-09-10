<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** STEP 35: registry of AI models/engines that can produce evaluation results. Never stores secrets. */
class AiModel extends Model
{
    protected $fillable = ['model_name', 'provider', 'model_type', 'task', 'version', 'configuration', 'is_active'];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'is_active' => 'boolean'];
    }

    public function evaluationRuns(): HasMany
    {
        return $this->hasMany(AiEvaluationRun::class, 'model_id');
    }
}
