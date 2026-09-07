<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recommendation extends Model
{
    use HasFactory;

    protected $fillable = [
        'analysis_report_id',
        'category',
        'problem',
        'title',
        'description',
        'explanation',
        'recommendation',
        'evidence',
        'source_metric',
        'priority',
        'status',
        'faculty_notes',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
        ];
    }

    /**
     * The analysis report this recommendation belongs to.
     */
    public function analysisReport(): BelongsTo
    {
        return $this->belongsTo(AnalysisReport::class);
    }
}
