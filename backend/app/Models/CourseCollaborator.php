<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseCollaborator extends Model
{
    public const ROLE_OWNER = 'OWNER';
    public const ROLE_EDITOR = 'EDITOR';
    public const ROLE_REVIEWER = 'REVIEWER';
    public const ROLE_VIEWER = 'VIEWER';
    /** Roles assignable to collaborators (OWNER is courses.user_id, never a membership row). */
    public const ASSIGNABLE_ROLES = [self::ROLE_EDITOR, self::ROLE_REVIEWER, self::ROLE_VIEWER];

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_DECLINED = 'DECLINED';
    public const STATUS_REVOKED = 'REVOKED';

    protected $fillable = ['course_id', 'user_id', 'invited_by', 'role', 'status', 'invited_at', 'accepted_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['invited_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
