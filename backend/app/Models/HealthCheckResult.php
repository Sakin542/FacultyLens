<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One recorded service probe (admin:monitor). Detail holds counts and exception class names only. */
class HealthCheckResult extends Model
{
    public $timestamps = false;

    protected $fillable = ['service', 'status', 'response_ms', 'detail', 'checked_at'];

    protected function casts(): array
    {
        return ['detail' => 'array', 'response_ms' => 'integer', 'checked_at' => 'datetime'];
    }
}
