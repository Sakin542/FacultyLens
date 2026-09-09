<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * STEP 31: Explicit question -> CO mapping decisions. Distinguishes faculty-confirmed mappings from
 * AI/semantic suggestions (STEP 11). Only CONFIRMED rows (and the question's own learning_outcome_id)
 * count as official.
 */
class QuestionCoMapping extends Model
{
    use HasFactory;

    public const SOURCE_FACULTY = 'FACULTY';
    public const SOURCE_AI = 'AI_SUGGESTED';
    public const SOURCE_IMPORTED = 'IMPORTED';

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_CONFIRMED = 'CONFIRMED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_CONFIRMED, self::STATUS_REJECTED];

    protected $fillable = ['question_id', 'learning_outcome_id', 'mapping_source', 'mapping_level', 'status', 'similarity_score', 'created_by', 'reviewed_at'];

    protected function casts(): array
    {
        return ['mapping_level' => 'integer', 'similarity_score' => 'decimal:4', 'reviewed_at' => 'datetime'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function learningOutcome(): BelongsTo
    {
        return $this->belongsTo(LearningOutcome::class);
    }
}
