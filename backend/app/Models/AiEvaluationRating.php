<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** STEP 35: human (faculty) quality rating of a generated artefact — a subjective evaluation signal. */
class AiEvaluationRating extends Model
{
    public const DECISIONS = ['ACCEPTED', 'REVISED', 'REJECTED'];

    protected $fillable = ['rateable_type', 'rateable_id', 'course_id', 'user_id', 'dimension_scores', 'overall_score', 'decision', 'comment'];

    protected function casts(): array
    {
        return ['dimension_scores' => 'array', 'overall_score' => 'float', 'rateable_id' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
