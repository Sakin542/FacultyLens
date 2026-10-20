<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Administrative task center entry: what ran, who started it, how it ended. Result files stay in private storage. */
class AdminOperation extends Model
{
    public const RUNNING = 'running';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    protected $fillable = [
        'type', 'label', 'status', 'initiated_by', 'total_count', 'success_count', 'failure_count', 'summary', 'error',
        'result_disk', 'result_path', 'result_filename', 'result_expires_at', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'total_count' => 'integer',
            'success_count' => 'integer',
            'failure_count' => 'integer',
            'result_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function hasDownloadableResult(): bool
    {
        return $this->status === self::COMPLETED && $this->result_path !== null
            && ($this->result_expires_at === null || $this->result_expires_at->isFuture());
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'label' => $this->label,
            'status' => $this->status,
            'initiated_by' => $this->initiator?->only(['id', 'name']),
            'total_count' => $this->total_count,
            'success_count' => $this->success_count,
            'failure_count' => $this->failure_count,
            'summary' => $this->summary,
            'error' => $this->error,
            'result_filename' => $this->result_filename,
            'result_available' => $this->hasDownloadableResult(),
            'result_expires_at' => $this->result_expires_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'duration_ms' => $this->started_at && $this->completed_at ? (int) $this->started_at->diffInMilliseconds($this->completed_at) : null,
        ];
    }
}
