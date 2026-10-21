<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sign-in throttling: tight per account, generous per address. A campus usually reaches the server through one public
 * IP, so a per-IP-only limit let colleagues signing in at the same time lock each other out.
 */
class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_guessing_one_accounts_password_is_throttled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->assertNotSame(429, $this->postJson('/api/auth/login', ['email' => 'target@university.edu', 'password' => "guess-{$i}"])->status());
        }
        $this->postJson('/api/auth/login', ['email' => 'Target@University.edu ', 'password' => 'guess-10'])->assertStatus(429);
    }

    public function test_colleagues_behind_the_same_address_are_not_locked_out(): void
    {
        // 30 different people from one campus IP: the old 20-per-IP limit refused the last ten
        for ($i = 0; $i < 30; $i++) {
            $this->assertNotSame(429, $this->postJson('/api/auth/login', ['email' => "teacher{$i}@university.edu", 'password' => 'wrong-password'])->status(), "teacher {$i}");
        }
        // One account being hammered does not block the others
        for ($i = 0; $i < 11; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'busy@university.edu', 'password' => "x{$i}"]);
        }
        $this->assertNotSame(429, $this->postJson('/api/auth/login', ['email' => 'other@university.edu', 'password' => 'wrong-password'])->status());
    }
}
