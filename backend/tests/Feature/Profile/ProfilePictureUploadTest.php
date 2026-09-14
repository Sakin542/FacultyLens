<?php

namespace Tests\Feature\Profile;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class ProfilePictureUploadTest extends ProfilePictureTestCase
{
    public function test_unauthenticated_upload_is_rejected(): void
    {
        $this->post('/api/profile/picture', ['profile_picture' => $this->fixtureUpload('valid.png')], ['Accept' => 'application/json'])
            ->assertStatus(401);

        $this->assertSame(0, count(Storage::disk('local')->allFiles()));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('validFixtures')]
    public function test_authenticated_user_can_upload_each_supported_format(string $fixture): void
    {
        $user = User::factory()->create();

        $response = $this->upload($user, $fixture);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Profile picture updated successfully.')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonMissingPath('user.profile_picture_path')
            ->assertJsonMissingPath('user.password');

        $url = $response->json('user.profile_picture_url');
        $this->assertIsString($url);
        $this->assertStringStartsWith("/api/users/{$user->id}/profile-picture?v=", $url);

        $path = $this->storedPath($user);
        $this->assertNotNull($path);
        $this->assertStringStartsWith("profile-pictures/{$user->id}/", $path);
        Storage::disk('local')->assertExists($path);
        $this->assertNotNull($user->fresh()->profile_picture_updated_at);

        // Filenames are generated server-side; the client name never reaches storage.
        $this->assertStringNotContainsString(pathinfo($fixture, PATHINFO_FILENAME), $path);
        $this->assertMatchesRegularExpression('#/[0-9a-f-]{36}\.(webp|png|jpg)$#', $path);
    }

    public static function validFixtures(): array
    {
        return [
            'jpeg' => ['valid.jpg'],
            'png' => ['valid.png'],
            'webp' => ['valid.webp'],
        ];
    }

    public function test_image_is_normalised_to_a_square_webp_when_gd_is_available(): void
    {
        if (!$this->gdAvailable()) {
            $this->markTestSkipped('GD is not available on this PHP build.');
        }
        $user = User::factory()->create();

        $this->upload($user, 'valid-portrait.png')->assertOk();

        $path = $this->storedPath($user);
        $this->assertStringEndsWith(function_exists('imagewebp') ? '.webp' : '.png', $path);
        $info = getimagesizefromstring(Storage::disk('local')->get($path));
        $this->assertSame(512, $info[0]);
        $this->assertSame(512, $info[1]);
    }

    public function test_upload_writes_an_audit_entry_without_sensitive_data(): void
    {
        $user = User::factory()->create();

        $this->upload($user, 'valid.png')->assertOk();

        $log = AuditLog::where('action', 'PROFILE_PICTURE_UPLOADED')->where('user_id', $user->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('User', $log->entity_type);
        $this->assertSame('image/png', $log->metadata['source_mime']);
        $this->assertArrayNotHasKey('path', $log->metadata);
        $this->assertStringNotContainsString('profile-pictures/', json_encode($log->metadata));
    }

    public function test_current_user_endpoints_expose_only_the_authenticated_url(): void
    {
        $user = User::factory()->create();
        $this->upload($user, 'valid.jpg')->assertOk();

        foreach (['/api/auth/user', '/api/profile'] as $endpoint) {
            $this->actingAs($user, 'sanctum')->getJson($endpoint)
                ->assertOk()
                ->assertJsonPath('user.id', $user->id)
                ->assertJsonMissingPath('user.profile_picture_path')
                ->assertJsonMissingPath('user.password')
                ->assertJsonMissingPath('user.remember_token');
            $this->assertStringStartsWith("/api/users/{$user->id}/profile-picture?v=", $this->actingAs($user, 'sanctum')->getJson($endpoint)->json('user.profile_picture_url'));
        }
    }

    public function test_user_without_picture_has_null_url(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/auth/user')
            ->assertOk()
            ->assertJsonPath('user.profile_picture_url', null);
    }

    public function test_own_picture_streams_from_private_storage_with_safe_headers(): void
    {
        $user = User::factory()->create();
        $this->upload($user, 'valid.png')->assertOk();

        $response = $this->actingAs($user, 'sanctum')->get('/api/profile/picture');

        $response->assertOk();
        $this->assertMatchesRegularExpression('#^image/(webp|png)$#', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));

        $body = $response->streamedContent();
        $this->assertNotEmpty($body);
        $this->assertNotFalse(getimagesizefromstring($body));
    }

    public function test_own_picture_endpoint_returns_404_when_none_is_set(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/profile/picture')->assertStatus(404);
    }
}
