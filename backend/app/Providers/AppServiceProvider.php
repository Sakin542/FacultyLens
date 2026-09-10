<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // STEP 34: per-request memoised role resolution
        $this->app->singleton(\App\Services\CourseAccessService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Policy class name doesn't follow the auto-discovery convention (model is DocumentProcessing).
        Gate::policy(\App\Models\DocumentProcessing::class, \App\Policies\DocumentPolicy::class);

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

        // STEP 32: Academic chat messages (configurable via CHAT_RATE_LIMIT)
        RateLimiter::for('academic-chat', function (Request $request) {
            return Limit::perMinute((int) config('academic_chat.rate_limit_per_minute', 30))
                ->by($request->user()?->id ?: $request->ip());
        });

        // STEP 33: Question generation is expensive (configurable via QUESTION_GENERATION_RATE_LIMIT)
        RateLimiter::for('question-generation', function (Request $request) {
            return Limit::perMinute((int) config('question_generation.rate_limit_per_minute', 10))
                ->by($request->user()?->id ?: $request->ip());
        });

        // STEP 34: collaboration invitations (per hour) and comments (per minute)
        RateLimiter::for('collaboration-invite', function (Request $request) {
            return Limit::perHour((int) config('collaboration.invite_rate_limit_per_hour', 10))
                ->by($request->user()?->id ?: $request->ip());
        });
        RateLimiter::for('collaboration-comment', function (Request $request) {
            return Limit::perMinute((int) config('collaboration.comment_rate_limit_per_minute', 60))
                ->by($request->user()?->id ?: $request->ip());
        });
    }
}
