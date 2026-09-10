<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Threaded discussion on an academic resource. Comments never modify the resource they discuss.
 * commentable_type is an allow-listed key from config('collaboration.commentables'), not a class name.
 */
class CollaborationComment extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_RESOLVED = 'RESOLVED';
    public const STATUS_DELETED = 'DELETED';

    protected $fillable = [
        'course_id', 'user_id', 'commentable_type', 'commentable_id', 'parent_id', 'body', 'mentions', 'status',
        'resolved_by', 'resolved_at', 'edited_at',
    ];

    protected function casts(): array
    {
        return ['mentions' => 'array', 'resolved_at' => 'datetime', 'edited_at' => 'datetime', 'commentable_id' => 'integer'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('id');
    }
}
