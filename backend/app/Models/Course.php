<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'course_code',
        'course_name',
        'description',
        'semester',
        'academic_year',
        'credits',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'credits' => 'integer',
        ];
    }

    /**
     * The faculty member that owns the course.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The course learning outcomes.
     */
    public function learningOutcomes(): HasMany
    {
        return $this->hasMany(LearningOutcome::class)->orderBy('sort_order');
    }

    /**
     * The assessments created for this course.
     */
    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    /**
     * Historical previous questions for similarity detection.
     */
    public function previousQuestions(): HasMany
    {
        return $this->hasMany(PreviousQuestion::class);
    }

    /**
     * Course syllabus and reference materials.
     */
    public function materials(): HasMany
    {
        return $this->hasMany(CourseMaterial::class);
    }
}
