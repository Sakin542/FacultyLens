<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_registration_creates_user_and_logs_in(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Dr. Jane Smith',
            'email' => 'jane.smith@faculty.aust.edu',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'department' => 'Computer Science',
            'designation' => 'Associate Professor',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'status',
                'message',
                'user' => ['id', 'name', 'email', 'department', 'designation'],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'jane.smith@faculty.aust.edu',
            'name' => 'Dr. Jane Smith',
        ]);

        $user = User::where('email', 'jane.smith@faculty.aust.edu')->first();
        $this->assertTrue(Hash::check('Password123!', $user->password));
        $this->assertArrayNotHasKey('password', $response->json('user'));
    }

    public function test_registration_fails_on_duplicate_email(): void
    {
        User::factory()->create(['email' => 'existing@faculty.aust.edu']);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Another User',
            'email' => 'existing@faculty.aust.edu',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'department' => 'CSE',
            'designation' => 'Lecturer',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_registration_fails_on_password_mismatch(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Dr. Jane Smith',
            'email' => 'jane@faculty.aust.edu',
            'password' => 'Password123!',
            'password_confirmation' => 'DifferentPassword!',
            'department' => 'CSE',
            'designation' => 'Lecturer',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_valid_login_returns_user_data_and_authenticates(): void
    {
        $user = User::factory()->create([
            'email' => 'faculty@aust.edu',
            'password' => Hash::make('Secret123!'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'faculty@aust.edu',
            'password' => 'Secret123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('user.email', 'faculty@aust.edu');

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_invalid_password(): void
    {
        User::factory()->create([
            'email' => 'faculty@aust.edu',
            'password' => Hash::make('Secret123!'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'faculty@aust.edu',
            'password' => 'WrongPassword',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Invalid email or password']);

        $this->assertGuest();
    }

    public function test_login_fails_with_nonexistent_account(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nobody@aust.edu',
            'password' => 'AnyPassword123',
        ]);

        $response->assertStatus(401);
        $this->assertGuest();
    }

    public function test_authenticated_user_can_access_current_user_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Prof. Alan Turing',
            'email' => 'turing@aust.edu',
            'department' => 'CSE',
            'designation' => 'Professor',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/auth/user');

        $response->assertStatus(200)
            ->assertJsonPath('user.name', 'Prof. Alan Turing')
            ->assertJsonPath('user.email', 'turing@aust.edu');
    }

    public function test_unauthenticated_user_cannot_access_user_profile(): void
    {
        $response = $this->getJson('/api/auth/user');
        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/auth/logout');

        $response->assertStatus(200)
            ->assertJson(['status' => 'success', 'message' => 'Logout successful']);
    }
}
