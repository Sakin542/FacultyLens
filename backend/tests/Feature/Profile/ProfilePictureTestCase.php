<?php

namespace Tests\Feature\Profile;

use App\Models\Course;
use App\Models\CourseCollaborator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared scaffolding for the profile-picture suite: faked private disk, synthetic fixtures, upload helper.
 */
abstract class ProfilePictureTestCase extends TestCase
{
    use RefreshDatabase;

    public const FIXTURES = __DIR__ . '/../../Fixtures/profile-pictures';

    protected function setUp(): void
    {
        parent::setUp();
        config(['profile_picture.disk' => 'local', 'profile_picture.require_processing' => false]);
        Storage::fake('local');
    }

    protected function fixturePath(string $name): string
    {
        $path = realpath(self::FIXTURES . '/' . $name);
        if ($path === false) {
            $this->fail("Fixture {$name} is missing; run tests/Fixtures/profile-pictures/generate.py");
        }

        return $path;
    }

    /** Copies the fixture to a temp file so the framework can never move/destroy the committed fixture. */
    protected function fixtureUpload(string $name, ?string $asName = null, ?string $mime = null): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'flpp');
        copy($this->fixturePath($name), $tmp);
        $asName ??= $name;
        $mime ??= match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'text/plain',
        };

        return new UploadedFile($tmp, $asName, $mime, null, true);
    }

    protected function upload(User $user, string $fixture, ?string $asName = null, ?string $mime = null): TestResponse
    {
        return $this->actingAs($user, 'sanctum')->post('/api/profile/picture', [
            'profile_picture' => $this->fixtureUpload($fixture, $asName, $mime),
        ], ['Accept' => 'application/json']);
    }

    protected function storedPath(User $user): ?string
    {
        return $user->fresh()->getAttribute('profile_picture_path');
    }

    protected function gdAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring');
    }

    protected function makeCourse(User $owner): Course
    {
        return Course::create([
            'user_id' => $owner->id,
            'course_code' => 'CSE-' . random_int(100, 999),
            'course_name' => 'Shared Course',
            'semester' => 'Fall',
            'academic_year' => '2026',
            'credits' => 3,
        ]);
    }

    protected function addCollaborator(Course $course, User $member, string $status = CourseCollaborator::STATUS_ACTIVE): CourseCollaborator
    {
        return CourseCollaborator::create([
            'course_id' => $course->id,
            'user_id' => $member->id,
            'invited_by' => $course->user_id,
            'role' => CourseCollaborator::ROLE_REVIEWER,
            'status' => $status,
            'invited_at' => now(),
            'accepted_at' => $status === CourseCollaborator::STATUS_ACTIVE ? now() : null,
        ]);
    }
}
