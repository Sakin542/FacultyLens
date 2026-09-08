<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RubricCriterion extends Model
{
    use HasFactory;

    protected $table = 'rubric_criteria';

    protected $fillable = [
        'rubric_id',
        'criterion',
        'description',
        'max_marks',
        'scoring_guidance',
        'expected_indicators',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'max_marks' => 'decimal:2',
            'expected_indicators' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(Rubric::class);
    }
}
