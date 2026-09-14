<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Hardening: path traversal, stale/foreign references, polyglot uploads, rate limiting, header hygiene.
 */
class ProfilePictureSecurityTest extends ProfilePictureTestCase
{
    public function test_tampered_reference_with_path_traversal_is_never_served(): void
    {
        $user = User::factory()->create();
        Storage::disk('local')->put('secret/config.txt', 'DB_PASSWORD=not-for-you');

        foreach (['../../.env', 'profile-pictures/' . $user->id . '/../../secret/config.txt', '/etc/passwd', 'secret/config.txt'] as $path) {
            $user->forceFill(['profile_picture_path' => $path])->save();

            $response = $this->actingAs($user, 'sanctum')->get('/api/profile/picture');

            $response->assertStatus(404);
            $this->assertStringNotContainsString('DB_PASSWORD', $response->getContent());
        }
    }

    public function test_reference_pointing_into_another_users_folder_is_rejected(): void
    {
        $victim = User::factory()->create();
        $attacker = User::factory()->create();
        $this->upload($victim, 'valid.png')->assertOk();
        $attacker->forceFill(['profile_picture_path' => $this->storedPath($victim)])->save();

        $this->actingAs($attacker, 'sanctum')->getJson('/api/profile/picture')->assertStatus(404);
    }

    public function test_reference_with_disallowed_extension_is_rejected(): void
    {
        $user = User::factory()->create();
        Storage::disk('local')->put("profile-pictures/{$user->id}/evil.php", '<?php echo 1;');
        $user->forceFill(['profile_picture_path' => "profile-pictures/{$user->id}/evil.php"])->save();

        $this->actingAs($user, 'sanctum')->getJson('/api/profile/picture')->assertStatus(404);
    }

    public function test_missing_file_behind_a_valid_reference_returns_404_without_details(): void
    {
        $user = User::factory()->create();
        $this->upload($user, 'valid.png')->assertOk();
        Storage::disk('local')->delete($this->storedPath($user));

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/profile/picture');

        $response->assertStatus(404)->assertJsonPath('message', 'No profile picture found.');
        $this->assertStringNotContainsString('profile-pictures/', $response->getContent());
    }

    public function test_original_filename_never_influences_the_stored_name(): void
    {
        $user = User::factory()->create();

        $this->upload($user, 'valid.png', '../../evil name<script>.png', 'image/png')->assertOk();

        $path = $this->storedPath($user);
        $this->assertStringNotContainsString('evil', $path);
        $this->assertStringNotContainsString('<', $path);
        $this->assertStringNotContainsString('..', $path);
        $this->assertMatchesRegularExpression("#^profile-pictures/{$user->id}/[0-9a-f-]{36}\.(webp|png)$#", $path);
    }

    public function test_polyglot_image_is_re_encoded_so_appended_payload_is_stripped(): void
    {
        $user = User::factory()->create();

        $this->upload($user, 'polyglot.png')->assertOk();

        $stored = Storage::disk('local')->get($this->storedPath($user));
        if ($this->gdAvailable()) {
            $this->assertStringNotContainsString('<?php', $stored);
        } else {
            // Without GD the validated original is kept; it is still only served as an image with nosniff.
            $this->assertNotFalse(getimagesizefromstring($stored));
        }
    }

    public function test_upload_and_delete_are_rate_limited_per_user(): void
    {
        config(['profile_picture.rate_limit_per_hour' => 3]);
        $user = User::factory()->create();

        $this->upload($user, 'valid.png')->assertOk();
        $this->actingAs($user, 'sanctum')->deleteJson('/api/profile/picture')->assertOk();
        $this->upload($user, 'valid.jpg')->assertOk();

        $this->upload($user, 'valid.webp')
            ->assertStatus(429)
            ->assertJsonPath('message', 'You have changed your profile picture too many times. Please try again later.');

        // Another user is not affected by the first user's bucket.
        $this->upload(User::factory()->create(), 'valid.png')->assertOk();
    }

    public function test_viewing_is_not_subject_to_the_change_rate_limit(): void
    {
        config(['profile_picture.rate_limit_per_hour' => 1]);
        $user = User::factory()->create();
        $this->upload($user, 'valid.png')->assertOk();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user, 'sanctum')->get('/api/profile/picture')->assertOk();
        }
    }

    public function test_image_response_never_exposes_storage_details(): void
    {
        $user = User::factory()->create();
        $this->upload($user, 'valid.png')->assertOk();

        $response = $this->actingAs($user, 'sanctum')->get('/api/profile/picture');

        $response->assertOk();
        $headers = json_encode($response->headers->all());
        $this->assertStringNotContainsString('profile-pictures/', $headers);
        $this->assertStringNotContainsString(storage_path(), $headers);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_error_responses_are_json_without_stack_traces(): void
    {
        $user = User::factory()->create();

        $response = $this->upload($user, 'fake.jpg', 'x.jpg', 'image/jpeg');

        $response->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['profile_picture']]);
        $this->assertStringNotContainsString('Stack trace', $response->getContent());
        $this->assertStringNotContainsString('vendor/', $response->getContent());
    }

    public function test_profile_picture_upload_does_not_alter_credentials_or_role(): void
    {
        $user = User::factory()->create();
        $hash = $user->password;

        $this->actingAs($user, 'sanctum')->post('/api/profile/picture', [
            'profile_picture' => $this->fixtureUpload('valid.png'),
            'role' => 'ADMIN',
            'password' => 'hacked',
            'email' => 'attacker@example.com',
        ], ['Accept' => 'application/json'])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame($hash, $fresh->password);
        $this->assertSame($user->email, $fresh->email);
        $this->assertFalse($fresh->isAdmin());
    }
}
