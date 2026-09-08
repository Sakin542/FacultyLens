<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * STEP 26: Minimal student identity, owned by the faculty member who registered it.
 */
class Student extends Model
{
    use HasFactory;

    protected $fillable = [
        'created_by',
        'student_identifier',
        'name',
        'email',
        'department',
        'program',
        'academic_year',
        'section',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(StudentSubmission::class);
    }
}
