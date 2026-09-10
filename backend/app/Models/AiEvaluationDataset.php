<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiEvaluationDataset extends Model
{
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_READY = 'READY';
    public const STATUS_RUNNING = 'RUNNING';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_ARCHIVED = 'ARCHIVED';

    protected $fillable = ['name', 'description', 'task', 'version', 'source', 'split', 'status', 'course_id', 'created_by', 'validation_report', 'validated_at'];

    protected function casts(): array
    {
        return ['validation_report' => 'array', 'validated_at' => 'datetime'];
    }

    public function examples(): HasMany
    {
        return $this->hasMany(AiEvaluationExample::class, 'dataset_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AiEvaluationRun::class, 'dataset_id')->orderByDesc('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
