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

        // Public newsletter sign-ups (per IP per hour)
        RateLimiter::for('newsletter', function (Request $request) {
            return Limit::perHour(max(1, (int) config('newsletter.subscribe_rate_limit_per_hour', 5)))
                ->by('newsletter:' . $request->ip())
                ->response(fn () => response()->json([
                    'status' => 'error',
                    'message' => 'Too many subscription attempts. Please try again later.',
                ], 429));
        });

        // Profile picture changes (upload/replace/remove) per user per hour
        RateLimiter::for('profile-picture', function (Request $request) {
            return Limit::perHour(max(1, (int) config('profile_picture.rate_limit_per_hour', 10)))
                ->by('profile-picture:' . ($request->user()?->id ?: $request->ip()))
                ->response(fn () => response()->json([
                    'status' => 'error',
                    'message' => 'You have changed your profile picture too many times. Please try again later.',
                ], 429));
        });

        // Password recovery: per IP and per target address. The 429 body is identical whether or not the account exists.
        RateLimiter::for('password-reset', function (Request $request) {
            $tooMany = fn () => response()->json([
                'status' => 'error',
                'message' => 'Too many password reset attempts. Please wait a few minutes and try again.',
            ], 429);
            $email = strtolower(trim((string) $request->input('email', '')));

            return [
                Limit::perMinute(5)->by('password-reset:ip:' . $request->ip())->response($tooMany),
                Limit::perHour(20)->by('password-reset:ip-hour:' . $request->ip())->response($tooMany),
                Limit::perHour(6)->by('password-reset:email:' . sha1($email))->response($tooMany),
            ];
        });

        // Operator e-mail test messages (real SMTP traffic) per user per hour
        RateLimiter::for('email-test', function (Request $request) {
            return Limit::perHour(max(1, (int) config('email.test_rate_limit_per_hour', 5)))
                ->by('email-test:' . ($request->user()?->id ?: $request->ip()))
                ->response(fn () => response()->json([
                    'status' => 'error',
                    'message' => 'Too many test e-mails requested. Please try again later.',
                ], 429));
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
