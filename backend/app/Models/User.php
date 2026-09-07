<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'department',
        'designation',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the courses managed by this faculty member.
     */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    /**
     * Get the historical previous questions added by this faculty member.
     */
    public function previousQuestions(): HasMany
    {
        return $this->hasMany(PreviousQuestion::class);
    }

    /**
     * Get the course materials uploaded by this faculty member.
     */
    public function uploadedMaterials(): HasMany
    {
        return $this->hasMany(CourseMaterial::class, 'uploaded_by');
    }

    /**
     * Feedback submitted by this faculty member on AI recommendations.
     */
    public function recommendationFeedbacks(): HasMany
    {
        return $this->hasMany(RecommendationFeedback::class);
    }

    /**
     * Decisions logged by this faculty member on AI recommendations.
     */
    public function recommendationDecisions(): HasMany
    {
        return $this->hasMany(RecommendationDecision::class);
    }

    /**
     * AI improvement signals derived from this faculty member's feedback.
     */
    public function aiImprovementSignals(): HasMany
    {
        return $this->hasMany(AiImprovementSignal::class);
    }

    /**
     * Audit log events associated with this user.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Check if user is an administrator.
     */
    public function isAdmin(): bool
    {
        return strtoupper($this->role ?? 'FACULTY') === 'ADMIN';
    }

    /**
     * Check if user is a faculty member.
     */
    public function isFaculty(): bool
    {
        return strtoupper($this->role ?? 'FACULTY') === 'FACULTY';
    }

    /**
     * Check if user matches a specific role.
     */
    public function hasRole(string $role): bool
    {
        return strtoupper($this->role ?? 'FACULTY') === strtoupper($role);
    }
}
