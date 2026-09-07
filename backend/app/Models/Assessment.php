<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'title',
        'type',
        'description',
        'assessment_date',
        'total_marks',
        'duration_minutes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'assessment_date' => 'date',
            'total_marks' => 'decimal:2',
            'duration_minutes' => 'integer',
        ];
    }

    /**
     * The course this assessment belongs to.
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * The questions belonging to this assessment.
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('question_number');
    }

    /**
     * The AI analysis reports generated for this assessment.
     */
    public function analysisReports(): HasMany
    {
        return $this->hasMany(AnalysisReport::class);
    }

    /**
     * Get the latest analysis report.
     */
    public function latestAnalysisReport()
    {
        return $this->hasOne(AnalysisReport::class)->latestOfMany();
    }
}

