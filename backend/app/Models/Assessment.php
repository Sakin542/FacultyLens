<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
     * The uploaded question paper file for this assessment.
     */
    public function questionPaper(): HasOne
    {
        return $this->hasOne(AssessmentQuestionPaper::class);
    }

    /**
     * The AI analysis reports generated for this assessment.
     */
    public function analysisReports(): HasMany
    {
        return $this->hasMany(AnalysisReport::class)->orderByDesc('analysis_version');
    }

    /**
     * Get the latest analysis report.
     */
    public function latestAnalysisReport()
    {
        return $this->hasOne(AnalysisReport::class)->latestOfMany();
    }

    /**
     * Generated assessment PDF/export reports for this assessment.
     */
    public function reports(): HasMany
    {
        return $this->hasMany(AssessmentReport::class)->orderByDesc('created_at');
    }

    /**
     * Get the latest generated assessment report.
     */
    public function latestReport()
    {
        return $this->hasOne(AssessmentReport::class)->latestOfMany();
    }

    /**
     * STEP 25: Rubrics generated for questions in this assessment.
     */
    public function rubrics(): HasMany
    {
        return $this->hasMany(Rubric::class);
    }

    /**
     * STEP 26: Student submissions for this assessment.
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(StudentSubmission::class);
    }

    /**
     * STEP 38: Immutable historical versions of this assessment (newest first).
     */
    public function versions(): HasMany
    {
        return $this->hasMany(AssessmentVersion::class)->orderByDesc('version_number');
    }
}

