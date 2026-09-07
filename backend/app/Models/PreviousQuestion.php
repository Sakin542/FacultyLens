<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreviousQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'course_id',
        'question_text',
        'question_type',
        'marks',
        'difficulty_level',
        'cognitive_level',
        'source',
        'source_year',
        'source_assessment',
    ];

    protected function casts(): array
    {
        return [
            'marks' => 'decimal:2',
        ];
    }

    /**
     * The faculty user that uploaded / owns this previous question.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The course this previous question is associated with.
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}

