<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One handwriting-OCR or lecture-transcription request from the faculty AI toolkit. */
class AiToolJob extends Model
{
    public const TOOLS = ['ocr', 'transcription', 'study_pack'];

    protected $fillable = [
        'user_id', 'tool', 'status', 'input_filename', 'input_path', 'input_bytes', 'language', 'result_text', 'result', 'error',
        'started_at', 'completed_at',
    ];

    protected $hidden = ['input_path'];

    protected function casts(): array
    {
        return ['result' => 'array', 'input_bytes' => 'integer', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toPayload(bool $withText = false): array
    {
        $payload = [
            'id' => $this->id,
            'tool' => $this->tool,
            'status' => $this->status,
            'input_filename' => $this->input_filename,
            'input_bytes' => $this->input_bytes,
            'language' => $this->language,
            'error' => $this->error,
            'model' => $this->result['model'] ?? null,
            'provider' => $this->result['provider'] ?? null,
            'fallback_reason' => $this->result['fallback_reason'] ?? null,
            'duration_seconds' => $this->result['duration_seconds'] ?? null,
            'line_count' => $this->result['line_count'] ?? null,
            'preview' => $this->result_text ? mb_substr($this->result_text, 0, 240) : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
        if ($withText) {
            $payload += [
                'text' => $this->result_text,
                'segments' => $this->result['segments'] ?? [],
                'pages' => $this->result['pages'] ?? [],
                'note' => $this->result['note'] ?? null,
                // Study pack
                'summary' => $this->result['summary'] ?? null,
                'summary_method' => $this->result['summary_method'] ?? null,
                'key_points' => $this->result['key_points'] ?? [],
                'quiz' => $this->result['quiz'] ?? [],
                'quiz_method' => $this->result['quiz_method'] ?? null,
                'quiz_rejected' => $this->result['quiz_rejected'] ?? null,
            ];
        }

        return $payload;
    }
}
