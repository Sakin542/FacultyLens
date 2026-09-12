<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * STEP 45: a faculty decision about an AI result (accept / reject / review / override).
 * Never alters the stored AI value — the AI value is snapshotted so the record stays meaningful after re-analysis.
 */
class AiResultReview extends Model
{
    public const ACTION_ACCEPTED = 'ACCEPTED';
    public const ACTION_REJECTED = 'REJECTED';
    public const ACTION_REVIEWED = 'REVIEWED';
    public const ACTION_OVERRIDDEN = 'OVERRIDDEN';

    protected $fillable = [
        'user_id', 'ai_result_type', 'ai_result_id', 'action', 'ai_value', 'override_value', 'override_reason',
        'comment', 'analysis_report_id', 'course_id', 'explanation_version',
    ];

    protected function casts(): array
    {
        return ['ai_value' => 'array', 'override_value' => 'array', 'ai_result_id' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function analysisReport(): BelongsTo
    {
        return $this->belongsTo(AnalysisReport::class);
    }
}
