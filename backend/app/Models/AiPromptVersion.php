<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiPromptVersion extends Model
{
    protected $fillable = ['feature', 'version', 'prompt_hash', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
