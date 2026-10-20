<?php

namespace Tests\Feature\Performance;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 43 §36 — the `api` limiter must actually be attached to the api middleware group (before that fix every
 * request returned 200). The limit is 300 requests / minute / user outside local development (one page load is
 * ~8-10 calls plus polling, so the former 120 was reached by normal multi-tab use); API_RATE_LIMIT_PER_MINUTE
 * overrides it.
 */
class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const LIMIT = 300;

    public function test_general_api_limiter_returns_429_after_the_per_user_limit(): void
    {
        RateLimiter::clear('api');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $statuses = [];
        for ($i = 1; $i <= self::LIMIT + 2; $i++) {
            $statuses[] = $this->getJson('/api/auth/user')->getStatusCode();
        }

        $this->assertSame(self::LIMIT, count(array_filter($statuses, fn ($s) => $s === 200)), 'exactly the configured number of requests is allowed per minute');
        $this->assertSame(429, $statuses[self::LIMIT]);
        $this->assertSame(429, $statuses[self::LIMIT + 1]);

        // another user has an independent bucket
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/auth/user')->assertOk()->assertHeader('X-RateLimit-Limit', (string) self::LIMIT);
    }
}
