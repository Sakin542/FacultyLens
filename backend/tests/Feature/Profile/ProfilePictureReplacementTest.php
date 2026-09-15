<?php

namespace Tests\Feature\Profile;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Profile\ProfileImageProcessor;
use App\Services\Profile\ProfilePictureException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Replacement + failure safety: the previous picture survives every failure mode and
 * is only deleted after the new reference has been committed.
 */
class ProfilePictureReplacementTest extends ProfilePictureTestCase
{
    public function test_replacing_stores_the_new_file_then_deletes_the_old_one(): void
    {
        $user = User::factory()->create();
        $this->upload($user, 'valid.png')->assertOk();
        $first = $this->storedPath($user);
        $firstVersion = $user->fresh()->profile_picture_url;
        $this->travel(2)->seconds();

        $response = $this->upload($user, 'valid.jpg');

        $response->assertOk()->assertJsonPath('message', 'Profile picture replaced successfully.');
        $second = $this->storedPath($user);
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertExists($second);
        Storage::disk('local')->assertMissing($first);
        $this->assertCount(1, Storage::disk('local')->allFiles("profile-pictures/{$user->id}"));
        $this->assertNotSame($firstVersion, $response->json('user.profile_picture_url'), 'cache-busting version must change');
        $this->assertNotNull(AuditLog::where('action', 'PROFILE_PICTURE_REPLACED')->where('user_id', $user->id)->first());
    }

    public function test_orphaned_files_in_the_users_folder_are_swept_on_replacement(): void
    {
        $user = User::factory()->create();
        $this->upload($user, 'valid.png')->assertOk();
        Storage::disk('local')->put("profile-pictures/{$user->id}/stale-orphan.webp", 'x');

        $this->upload($user, 'valid.jpg')->assertOk();

        $files = Storage::disk('local')->allFiles("profile-pictures/{$user->id}");
        $this->assertCount(1, $files);
        $this->assertSame($this->storedPath($user), $files[0]);
    }

    public function test_failed_validation_keeps_the_previous_picture(): void
    {
        $user = User::factory()->create();
        $this->upload($user, 'valid.png')->assertOk();
        $first = $this->storedPath($user);

        $this->upload($user, 'invalid.txt')->assertStatus(422);
        $this->upload($user, 'tiny.png')->assertStatus(422);

        $this->assertSame($first, $this->storedPath($user));
        Storage::disk('local')->assertExists($first);
        $this->assertCount(1, Storage::disk('local')->allFiles("profile-pictures/{$user->id}"));
    }

    public function test_image_processing_failure_leaves_database_and_old_image_untouched(): void
    {
        $user = User::factory()->create();
        // The router memoises the controller (and its service) per route within a test, so the mock must be
        // in place before the first request: pass the first call through, fail the second.
        $real = new ProfileImageProcessor();
        $calls = 0;
        $this->mock(ProfileImageProcessor::class, function ($mock) use ($real, &$calls) {
            $mock->shouldReceive('process')->andReturnUsing(function (string $path) use ($real, &$calls) {
                if (++$calls > 1) {
                    throw ProfilePictureException::processingFailed();
                }

                return $real->process($path);
            });
        });
        $this->upload($user, 'valid.png')->assertOk();
        $first = $this->storedPath($user);
        $updatedAt = $user->fresh()->profile_picture_updated_at;

        $response = $this->upload($user, 'valid.jpg');

        $response->assertStatus(422)->assertJsonPath('message', 'We could not process that image. Please try a different JPG, PNG, or WEBP file.');
        $this->assertSame($first, $this->storedPath($user));
        $this->assertEquals($updatedAt, $user->fresh()->profile_picture_updated_at);
        Storage::disk('local')->assertExists($first);
        $this->assertCount(1, Storage::disk('local')->allFiles("profile-pictures/{$user->id}"));
        $this->assertSame('processing_failed', AuditLog::where('action', 'PROFILE_PICTURE_UPLOAD_FAILED')->latest('id')->first()->metadata['reason']);
    }

    public function test_unexpected_processor_crash_is_reported_safely(): void
    {
        $user = User::factory()->create();
        $this->mock(ProfileImageProcessor::class, function ($mock) {
            $mock->shouldReceive('process')->once()->andThrow(new RuntimeException('imagecreatefromstring(): gd-webp cannot allocate /var/www/secret'));
        });

        $response = $this->upload($user, 'valid.jpg');

        $response->assertStatus(422);
        $this->assertStringNotContainsString('/var/www', $response->getContent());
        $this->assertStringNotContainsString('imagecreatefromstring', $response->getContent());
        $this->assertNull($this->storedPath($user));
    }

    public function test_processing_unavailable_is_a_503_when_strict_processing_is_required(): void
    {
        $user = User::factory()->create();
        config(['profile_picture.require_processing' => true]);
        // Simulate a PHP build without GD so this path is exercised regardless of the host's extensions.
        $this->app->instance(ProfileImageProcessor::class, new class extends ProfileImageProcessor {
            public function canNormalize(): bool
            {
                return false;
            }
        });

        $this->upload($user, 'valid.png')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Profile pictures cannot be processed right now. Please try again later.');

        $this->assertNull($this->storedPath($user));
    }

    public function test_storage_failure_leaves_database_and_old_image_untouched(): void
    {
        $user = User::factory()->create();
        $this->upload($user, 'valid.png')->assertOk();
        $first = $this->storedPath($user);
        $realDisk = Storage::disk('local');

        $failing = \Mockery::mock(Filesystem::class);
        $failing->shouldReceive('put')->once()->andReturn(false);
        Storage::set('local', $failing);

        $response = $this->upload($user, 'valid.jpg');

        Storage::set('local', $realDisk);
        $response->assertStatus(503)->assertJsonPath('message', 'Unable to update your profile picture. Please try again.');
        $this->assertSame($first, $this->storedPath($user));
        $realDisk->assertExists($first);
        $this->assertSame('storage_failed', AuditLog::where('action', 'PROFILE_PICTURE_UPLOAD_FAILED')->latest('id')->first()->metadata['reason']);
    }

    public function test_storage_exception_is_handled_like_a_failed_write(): void
    {
        $user = User::factory()->create();
        $realDisk = Storage::disk('local');
        $failing = \Mockery::mock(Filesystem::class);
        $failing->shouldReceive('put')->once()->andThrow(new RuntimeException('disk full at /srv/private'));
        Storage::set('local', $failing);

        $response = $this->upload($user, 'valid.png');

        Storage::set('local', $realDisk);
        $response->assertStatus(503);
        $this->assertStringNotContainsString('/srv/private', $response->getContent());
        $this->assertNull($this->storedPath($user));
    }

    public function test_database_failure_removes_the_newly_written_file(): void
    {
        $user = User::factory()->create();
        $this->upload($user, 'valid.png')->assertOk();
        $first = $this->storedPath($user);

        $fail = true;
        User::saving(function () use (&$fail) {
            if ($fail) {
                throw new RuntimeException('simulated database outage');
            }
        });

        try {
            $response = $this->upload($user, 'valid.jpg');
        } finally {
            $fail = false;
        }

        $response->assertStatus(503);
        $this->assertSame($first, $this->storedPath($user));
        Storage::disk('local')->assertExists($first);
        $this->assertCount(1, Storage::disk('local')->allFiles("profile-pictures/{$user->id}"), 'no orphan file may remain');
        $this->assertSame('database_failed', AuditLog::where('action', 'PROFILE_PICTURE_UPLOAD_FAILED')->latest('id')->first()->metadata['reason']);
    }
}
