<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Runtime key/value setting edited from the admin panel (feature toggles, announcement banner).
 */
class SystemSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
