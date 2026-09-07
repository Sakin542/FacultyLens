<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Standard API Rate Limiter
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Strict Rate Limiter for Authentication Attempts
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        // Dedicated Rate Limiter for Computationally Intensive AI Endpoints
        RateLimiter::for('ai-analysis', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // Dedicated Rate Limiter for File Uploads
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(25)->by($request->user()?->id ?: $request->ip());
        });
    }
}
