<?php

use App\Http\Controllers\Api\HealthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Health check endpoint for FacultyLens
 */
Route::get('/health', [HealthController::class, 'check']);

