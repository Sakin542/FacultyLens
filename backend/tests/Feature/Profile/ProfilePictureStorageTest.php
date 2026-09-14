<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use App\Services\Profile\ProfilePictureService;
use Illuminate\Support\Facades\Storage;

/**
 * Storage contract: private disk only, per-user folders, DB ↔ storage consistency.
 */
class ProfilePictureStorageTest extends ProfilePictureTestCase
{
    public function test_configured_disk_is_private(): void
    {
        $disk = (string) config('profile_picture.disk');

        $this->assertNotSame('public', $disk);
        $this->assertNotSame('public', config("filesystems.disks.{$disk}.visibility"));
    }

    public function test_files_are_written_under_a_per_user_private_folder(): void
    {
        $user = User::factory()->create();
        $service = app(ProfilePictureService::class);

        $this->upload($user, 'valid.png')->assertOk();

        $path = $this->storedPath($user);
        $this->assertSame("profile-pictures/{$user->id}", $service->userDirectory($user));
        $this->assertStringStartsWith($service->userDirectory($user) . '/', $path);
        Storage::disk('local')->assertExists($path);
        $this->assertTrue($service->isOwnedPath($user, $path));
        $this->assertFalse($service->isOwnedPath(User::factory()->create(), $path));
    }

    public function test_database_reference_always_matches_a_stored_object_after_each_operation(): void
    {
        $user = User::factory()->create();
        $service = app(ProfilePictureService::class);

        $this->assertNull($service->resolve($user->fresh()));

        $this->upload($user, 'valid.png')->assertOk();
        $resolved = $service->resolve($user->fresh());
        $this->assertNotNull($resolved);
        $this->assertSame($this->storedPath($user), $resolved['path']);
        $this->assertMatchesRegularExpression('#^image/(webp|png)$#', $resolved['mime']);
        $this->assertGreaterThan(0, $resolved['size']);

        $this->upload($user, 'valid.jpg')->assertOk();
        $this->assertSame($this->storedPath($user), $service->resolve($user->fresh())['path']);
        $this->assertCount(1, Storage::disk('local')->allFiles("profile-pictures/{$user->id}"));

        $this->actingAs($user, 'sanctum')->deleteJson('/api/profile/picture')->assertOk();
        $this->assertNull($service->resolve($user->fresh()));
        $this->assertFalse(Storage::disk('local')->exists("profile-pictures/{$user->id}"));
    }

    public function test_stored_bytes_are_a_decodable_image_of_the_expected_format(): void
    {
        $user = User::factory()->create();
        $this->upload($user, 'valid.jpg')->assertOk();

        $path = $this->storedPath($user);
        $bytes = Storage::disk('local')->get($path);
        $info = getimagesizefromstring($bytes);

        $this->assertNotFalse($info);
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $this->assertSame(ProfilePictureService::MIME_BY_EXTENSION[$ext], $info['mime']);
        if ($this->gdAvailable()) {
            $this->assertSame([512, 512], [$info[0], $info[1]]);
        }
    }

    public function test_disk_override_via_config_is_respected(): void
    {
        Storage::fake('avatars-test');
        config(['filesystems.disks.avatars-test.visibility' => 'private', 'profile_picture.disk' => 'avatars-test']);
        $user = User::factory()->create();

        $this->upload($user, 'valid.png')->assertOk();

        Storage::disk('avatars-test')->assertExists($this->storedPath($user));
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }
}
