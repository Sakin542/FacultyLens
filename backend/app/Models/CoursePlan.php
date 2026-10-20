<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A saved AI course-design draft: a syllabus (COs, CO→PO links, outline) or a week-by-week lesson plan. */
class CoursePlan extends Model
{
    public const KINDS = ['syllabus', 'lesson_plan'];

    protected $fillable = ['course_id', 'created_by', 'kind', 'inputs', 'content', 'applied_at'];

    protected function casts(): array
    {
        return ['inputs' => 'array', 'content' => 'array', 'applied_at' => 'datetime'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'kind' => $this->kind,
            'inputs' => $this->inputs,
            'content' => $this->content,
            'creator' => $this->creator?->only(['id', 'name']),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
