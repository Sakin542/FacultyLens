<?php

namespace Tests\Feature\Profile;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class ProfilePictureDeleteTest extends ProfilePictureTestCase
{
    public function test_user_can_remove_their_picture(): void
    {
        $user = User::factory()->create();
        $this->upload($user, 'valid.png')->assertOk();
        $path = $this->storedPath($user);
        Storage::disk('local')->assertExists($path);

        $this->actingAs($user, 'sanctum')->deleteJson('/api/profile/picture')
            ->assertOk()
            ->assertJsonPath('message', 'Profile picture removed.')
            ->assertJsonPath('user.profile_picture_url', null);

        $this->assertNull($this->storedPath($user));
        Storage::disk('local')->assertMissing($path);
        $this->assertCount(0, Storage::disk('local')->allFiles("profile-pictures/{$user->id}"));
        $this->assertNotNull(AuditLog::where('action', 'PROFILE_PICTURE_REMOVED')->where('user_id', $user->id)->first());

        $this->actingAs($user, 'sanctum')->getJson('/api/profile/picture')->assertStatus(404);
        $this->actingAs($user, 'sanctum')->getJson('/api/auth/user')->assertJsonPath('user.profile_picture_url', null);
    }

    public function test_removing_when_no_picture_exists_is_a_safe_no_op(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->deleteJson('/api/profile/picture')
            ->assertOk()
            ->assertJsonPath('message', 'No profile picture to remove.')
            ->assertJsonPath('user.profile_picture_url', null);

        $this->assertSame(0, AuditLog::where('action', 'PROFILE_PICTURE_REMOVED')->count());
    }

    public function test_removing_cleans_the_reference_even_when_the_file_is_already_missing(): void
    {
        $user = User::factory()->create();
        $this->upload($user, 'valid.png')->assertOk();
        $path = $this->storedPath($user);
        Storage::disk('local')->delete($path);

        $this->actingAs($user, 'sanctum')->deleteJson('/api/profile/picture')->assertOk();

        $this->assertNull($this->storedPath($user));
        $log = AuditLog::where('action', 'PROFILE_PICTURE_REMOVED')->where('user_id', $user->id)->first();
        $this->assertFalse($log->metadata['had_file']);
    }

    public function test_removal_does_not_touch_other_users_files(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->upload($user, 'valid.png')->assertOk();
        $this->upload($other, 'valid.jpg')->assertOk();
        $otherPath = $this->storedPath($other);

        $this->actingAs($user, 'sanctum')->deleteJson('/api/profile/picture')->assertOk();

        Storage::disk('local')->assertExists($otherPath);
        $this->assertSame($otherPath, $this->storedPath($other));
    }

    public function test_unauthenticated_delete_is_rejected(): void
    {
        $this->deleteJson('/api/profile/picture')->assertStatus(401);
    }
}
