<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseBackup extends Model
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'filename', 'disk', 'path', 'files_path', 'size_bytes', 'files_size_bytes', 'files_count', 'checksum_sha256', 'verification_status', 'verified_at', 'status', 'trigger',
        'driver', 'triggered_by', 'error', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'files_size_bytes' => 'integer',
            'files_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /** Admin-facing payload; the storage path stays server-side. */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'size_bytes' => $this->size_bytes,
            'has_files' => $this->files_path !== null,
            'files_size_bytes' => $this->files_size_bytes,
            'files_count' => $this->files_count,
            'checksum_sha256' => $this->checksum_sha256,
            'verification_status' => $this->verification_status,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'status' => $this->status,
            'trigger' => $this->trigger,
            'driver' => $this->driver,
            'error' => $this->error,
            'triggered_by' => $this->triggeredBy?->only(['id', 'name']),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
