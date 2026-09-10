<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'program_id',
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

    /** STEP 31: the program whose outcomes (PO) this course's COs map to. */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function coPoMappings(): HasMany
    {
        return $this->hasMany(CoPoMapping::class);
    }

    /** STEP 34: additional members (the owner is courses.user_id). */
    public function collaborators(): HasMany
    {
        return $this->hasMany(CourseCollaborator::class);
    }

    public function activeCollaborators(): HasMany
    {
        return $this->collaborators()->where('status', CourseCollaborator::STATUS_ACTIVE);
    }

    public function collaborationInvitations(): HasMany
    {
        return $this->hasMany(CourseCollaborationInvitation::class);
    }

    public function collaborationComments(): HasMany
    {
        return $this->hasMany(CollaborationComment::class);
    }

    /**
     * STEP 34: courses the user owns OR actively collaborates on. Authorization happens in SQL, never in the client.
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('courses.user_id', $user->id)
                ->orWhereExists(function ($sub) use ($user) {
                    $sub->selectRaw('1')->from('course_collaborators')
                        ->whereColumn('course_collaborators.course_id', 'courses.id')
                        ->where('course_collaborators.user_id', $user->id)
                        ->where('course_collaborators.status', CourseCollaborator::STATUS_ACTIVE);
                });
        });
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
