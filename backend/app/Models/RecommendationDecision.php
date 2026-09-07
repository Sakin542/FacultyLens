<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecommendationDecision extends Model
{
    use HasFactory;

    protected $fillable = [
        'recommendation_id',
        'user_id',
        'previous_status',
        'new_status',
        'reason',
    ];

    /**
     * The recommendation this decision applies to.
     */
    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class);
    }

    /**
     * The user (faculty member) who made the decision.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

