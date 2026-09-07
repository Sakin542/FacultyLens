<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecommendationFeedback extends Model
{
    use HasFactory;

    protected $table = 'recommendation_feedback';

    protected $fillable = [
        'recommendation_id',
        'user_id',
        'decision',
        'usefulness_rating',
        'reason',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'usefulness_rating' => 'integer',
        ];
    }

    /**
     * The recommendation this feedback belongs to.
     */
    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class);
    }

    /**
     * The user who submitted the feedback.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

