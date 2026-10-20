<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A research paper summary (the uploaded file itself is not kept; only the extracted summary). */
class PaperSummary extends Model
{
    protected $fillable = ['user_id', 'course_id', 'title', 'file_name', 'word_count', 'summary'];

    protected function casts(): array
    {
        return ['summary' => 'array', 'word_count' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function toPayload(bool $full = true): array
    {
        $s = (array) $this->summary;

        return [
            'id' => $this->id,
            'title' => $this->title ?: ($s['title'] ?? $this->file_name),
            'file_name' => $this->file_name,
            'word_count' => $this->word_count,
            'course' => $this->course ? ['id' => $this->course->id, 'course_code' => $this->course->course_code] : null,
            'authors' => $s['authors'] ?? [],
            'year' => $s['year'] ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
        ] + ($full ? ['summary' => $s] : []);
    }
}
