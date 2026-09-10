<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiEvaluationExample extends Model
{
    protected $fillable = ['dataset_id', 'input_data', 'expected_output', 'metadata', 'source', 'split', 'fingerprint', 'created_by'];

    protected function casts(): array
    {
        return ['input_data' => 'array', 'expected_output' => 'array', 'metadata' => 'array'];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(AiEvaluationDataset::class, 'dataset_id');
    }

    public static function fingerprintFor(array $input): string
    {
        ksort($input);

        return hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE));
    }
}
