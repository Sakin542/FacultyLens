<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** STEP 31: Program Outcome (PO). Wording is institution-configured, never hardcoded. */
class ProgramOutcome extends Model
{
    use HasFactory;

    protected $fillable = ['program_id', 'code', 'title', 'description', 'sort_order', 'status'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function coMappings(): HasMany
    {
        return $this->hasMany(CoPoMapping::class);
    }
}
