<?php

namespace Tests\Feature\Performance;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 43 §36 — the documented `api` limiter (120 requests / minute / user) must actually be attached to the api
 * middleware group. Before the fix 130 consecutive authenticated GETs all returned 200.
 */
class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_general_api_limiter_returns_429_after_120_requests_per_user(): void
    {
        RateLimiter::clear('api');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $statuses = [];
        for ($i = 1; $i <= 122; $i++) {
            $statuses[] = $this->getJson('/api/auth/user')->getStatusCode();
        }

        $this->assertSame(120, count(array_filter($statuses, fn ($s) => $s === 200)), 'exactly 120 requests are allowed per minute');
        $this->assertSame(429, $statuses[120]);
        $this->assertSame(429, $statuses[121]);

        // another user has an independent bucket
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/auth/user')->assertOk()->assertHeader('X-RateLimit-Limit', '120');
    }
}
