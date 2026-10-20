<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
        'profile_picture_path',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'google_id',
    ];

    /**
     * The profile picture is exposed only as an authenticated API URL, never as a storage path.
     *
     * @var list<string>
     */
    protected $appends = [
        'profile_picture_url',
    ];

    /** Columns needed to build a lightweight user reference (id, name, avatar) in nested payloads. */
    public const REF_COLUMNS = 'id,name,profile_picture_path,profile_picture_updated_at';

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
            'profile_picture_updated_at' => 'datetime',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** Deactivated accounts cannot sign in; an inactive flag of null (pre-migration rows) counts as active. */
    public function isActive(): bool
    {
        return $this->is_active !== false;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && !empty($this->two_factor_secret);
    }

    public function hasProfilePicture(): bool
    {
        return !empty($this->getAttribute('profile_picture_path'));
    }

    /**
     * Laravel's password broker calls this with the freshly created (plaintext) token. FacultyLens routes it through
     * the e-mail system (queue → Horizon → SMTP) instead of the framework's synchronous notification; the token is
     * placed only inside the reset URL and is never logged or stored by EmailService.
     */
    public function sendPasswordResetNotification($token): void
    {
        app(\App\Services\Email\EmailService::class)->sendPasswordReset($this, (string) $token);
    }

    /**
     * Relative, authenticated avatar URL with a version token for cache-busting; null when no picture is set.
     */
    public function getProfilePictureUrlAttribute(): ?string
    {
        if (!$this->hasProfilePicture()) {
            return null;
        }
        $version = $this->profile_picture_updated_at?->timestamp ?? $this->updated_at?->timestamp ?? 0;

        return "/api/users/{$this->id}/profile-picture?v={$version}";
    }

    /**
     * Safe account payload returned by auth/profile endpoints (never credentials or storage paths).
     *
     * @return array<string, mixed>
     */
    public function profilePayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role ?? 'FACULTY',
            'is_admin' => $this->isAdmin(),
            'department' => $this->department,
            'designation' => $this->designation,
            'profile_picture_url' => $this->profile_picture_url,
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
        ];
    }

    /**
     * Minimal reference used when embedding a user in another resource (comments, collaborators).
     *
     * @return array{id: int, name: string, profile_picture_url: ?string}
     */
    public function refPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'profile_picture_url' => $this->profile_picture_url,
        ];
    }

    /**
     * Get the courses managed by this faculty member.
     */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    /** STEP 34: memberships on other faculty's courses. */
    public function courseCollaborations(): HasMany
    {
        return $this->hasMany(CourseCollaborator::class);
    }

    public function collaborationComments(): HasMany
    {
        return $this->hasMany(CollaborationComment::class);
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
     * STEP 26: Students registered by this faculty member.
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'created_by');
    }

    /**
     * STEP 47: in-app notifications (FacultyLens model over Laravel's notifications table).
     * Overrides HasDatabaseNotifications so unreadNotifications()/readNotifications() use the same model.
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'notifiable')->latest();
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /**
     * Check if user is the administrator. When config('admin.email') is set, only that account qualifies,
     * so an ADMIN role written onto any other row grants nothing.
     */
    public function isAdmin(): bool
    {
        if (strtoupper($this->role ?? 'FACULTY') !== 'ADMIN') {
            return false;
        }
        $adminEmail = (string) config('admin.email', '');

        return $adminEmail === '' || strtolower((string) $this->email) === $adminEmail;
    }

    /** Department head: read-only oversight of every course in the user's department. */
    public function isDepartmentHead(): bool
    {
        return strtoupper($this->role ?? 'FACULTY') === 'DEPARTMENT_HEAD';
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
