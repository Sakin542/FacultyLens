<?php

namespace Tests\Feature\Profile;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Profile\ProfileImageProcessor;
use App\Services\Profile\ProfilePictureException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProfilePictureValidationTest extends ProfilePictureTestCase
{
    public function test_missing_file_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/profile/picture', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.profile_picture.0', 'Please select an image to upload.');
    }

    public function test_plain_text_file_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->upload($user, 'invalid.txt')
            ->assertStatus(422)
            ->assertJsonPath('errors.profile_picture.0', 'Please upload a JPG, PNG, or WEBP image.');

        $this->assertNull($this->storedPath($user));
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_fake_jpeg_with_non_image_content_is_rejected(): void
    {
        $user = User::factory()->create();

        // Declared as image/jpeg with a .jpg name, but the bytes are a PHP/GIF stub.
        $this->upload($user, 'fake.jpg', 'photo.jpg', 'image/jpeg')
            ->assertStatus(422)
            ->assertJsonPath('errors.profile_picture.0', 'Please upload a JPG, PNG, or WEBP image.');

        $this->assertNull($this->storedPath($user));
    }

    public function test_svg_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->upload($user, 'svg-vector.svg')->assertStatus(422);
        $this->upload($user, 'svg-vector.svg', 'avatar.png', 'image/png')->assertStatus(422);

        $this->assertNull($this->storedPath($user));
    }

    public function test_extension_must_match_an_allowed_image_type(): void
    {
        $user = User::factory()->create();

        // Real PNG bytes but a disallowed/double extension: rejected by the extension rule.
        $this->upload($user, 'valid.png', 'avatar.php', 'image/png')->assertStatus(422);
        $this->upload($user, 'valid.png', 'avatar.png.exe', 'image/png')->assertStatus(422);

        $this->assertNull($this->storedPath($user));
    }

    public function test_file_larger_than_limit_is_rejected_with_friendly_message(): void
    {
        $user = User::factory()->create();
        $maxMb = (int) config('profile_picture.max_size_mb', 5);
        $tooBig = UploadedFile::fake()->create('big.jpg', ($maxMb * 1024) + 1, 'image/jpeg');

        $response = $this->actingAs($user, 'sanctum')->post('/api/profile/picture', ['profile_picture' => $tooBig], ['Accept' => 'application/json']);

        $response->assertStatus(422)
            ->assertJsonPath('errors.profile_picture.0', "Profile picture must be {$maxMb} MB or smaller.");
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertNull($this->storedPath($user));
    }

    public function test_image_below_minimum_dimensions_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->upload($user, 'tiny.png')
            ->assertStatus(422)
            ->assertJsonPath('errors.profile_picture.0', 'Profile picture must be between 100 × 100 and 5000 × 5000 pixels.');
    }

    public function test_image_above_maximum_dimensions_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->upload($user, 'huge-dimensions.png')
            ->assertStatus(422)
            ->assertJsonPath('errors.profile_picture.0', 'Profile picture must be between 100 × 100 and 5000 × 5000 pixels.');
        $this->assertNull($this->storedPath($user));
    }

    public function test_processor_rejects_content_that_does_not_match_the_sniffed_type(): void
    {
        $processor = new ProfileImageProcessor();

        $this->expectException(ProfilePictureException::class);
        $processor->inspect($this->fixturePath('fake.jpg'));
    }

    public function test_processor_enforces_pixel_budget_independently_of_dimensions(): void
    {
        $processor = new ProfileImageProcessor(['allowed_mime_types' => ['image/png'], 'min_dimension' => 1, 'max_dimension' => 10000, 'max_pixels' => 1000]);

        try {
            $processor->inspect($this->fixturePath('valid.png'));
            $this->fail('Expected pixel budget rejection');
        } catch (ProfilePictureException $e) {
            $this->assertSame('too_many_pixels', $e->reason);
            $this->assertSame(422, $e->status);
        }
    }

    public function test_processor_inspect_accepts_every_valid_fixture(): void
    {
        $processor = new ProfileImageProcessor();

        $this->assertSame(['mime' => 'image/jpeg', 'width' => 160, 'height' => 120], $processor->inspect($this->fixturePath('valid.jpg')));
        $this->assertSame(['mime' => 'image/png', 'width' => 128, 'height' => 128], $processor->inspect($this->fixturePath('valid.png')));
        $this->assertSame(['mime' => 'image/webp', 'width' => 140, 'height' => 140], $processor->inspect($this->fixturePath('valid.webp')));
    }

    public function test_content_level_failure_is_audited_without_touching_the_database(): void
    {
        $user = User::factory()->create();
        $this->mock(ProfileImageProcessor::class, function ($mock) {
            $mock->shouldReceive('process')->once()->andThrow(ProfilePictureException::invalid('The image content does not match its type.', 'mime_mismatch'));
        });

        $this->upload($user, 'valid.png')->assertStatus(422)->assertJsonPath('message', 'The image content does not match its type.');

        $this->assertNull($this->storedPath($user));
        $log = AuditLog::where('action', 'PROFILE_PICTURE_UPLOAD_FAILED')->where('user_id', $user->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('mime_mismatch', $log->metadata['reason']);
    }
}
