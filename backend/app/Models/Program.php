<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** STEP 31: Academic program owning a set of Program Outcomes (PO). Faculty-scoped via created_by. */
class Program extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_ARCHIVED = 'ARCHIVED';
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_ARCHIVED];

    protected $fillable = ['code', 'name', 'description', 'department', 'status', 'created_by'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function outcomes(): HasMany
    {
        return $this->hasMany(ProgramOutcome::class)->orderBy('sort_order');
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }
}
