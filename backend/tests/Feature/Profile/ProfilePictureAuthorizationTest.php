<?php

namespace Tests\Feature\Profile;

use App\Models\CourseCollaborator;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * IDOR / authorization: the target account for mutations is always the session user; viewing another
 * faculty member's picture requires a shared course (or admin).
 */
class ProfilePictureAuthorizationTest extends ProfilePictureTestCase
{
    public function test_user_cannot_replace_another_users_picture_by_supplying_a_user_id(): void
    {
        $victim = User::factory()->create();
        $attacker = User::factory()->create();
        $this->upload($victim, 'valid.png')->assertOk();
        $victimPath = $this->storedPath($victim);

        $response = $this->actingAs($attacker, 'sanctum')->post('/api/profile/picture', [
            'profile_picture' => $this->fixtureUpload('valid.jpg'),
            'user_id' => $victim->id,
            'id' => $victim->id,
        ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('user.id', $attacker->id);
        $this->assertSame($victimPath, $this->storedPath($victim), 'victim picture must be unchanged');
        $this->assertStringStartsWith("profile-pictures/{$attacker->id}/", $this->storedPath($attacker));
    }

    public function test_user_cannot_delete_another_users_picture(): void
    {
        $victim = User::factory()->create();
        $attacker = User::factory()->create();
        $this->upload($victim, 'valid.png')->assertOk();
        $victimPath = $this->storedPath($victim);

        $this->actingAs($attacker, 'sanctum')->deleteJson('/api/profile/picture', ['user_id' => $victim->id])
            ->assertOk()
            ->assertJsonPath('user.id', $attacker->id);
        $this->actingAs($attacker, 'sanctum')->deleteJson("/api/users/{$victim->id}/profile-picture")->assertStatus(405);

        $this->assertSame($victimPath, $this->storedPath($victim));
    }

    public function test_unrelated_user_cannot_view_another_users_picture(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $this->upload($owner, 'valid.png')->assertOk();

        $this->actingAs($stranger, 'sanctum')->getJson("/api/users/{$owner->id}/profile-picture")
            ->assertStatus(403)
            ->assertJsonPath('message', 'You are not allowed to view this profile picture.');
    }

    public function test_unauthenticated_request_cannot_view_any_picture(): void
    {
        $owner = User::factory()->create();
        app(\App\Services\Profile\ProfilePictureService::class)->upload($owner, $this->fixtureUpload('valid.png'));

        $this->getJson("/api/users/{$owner->id}/profile-picture")->assertStatus(401);
        $this->getJson('/api/profile/picture')->assertStatus(401);
    }

    public function test_pictures_are_not_reachable_through_the_public_storage_url(): void
    {
        $owner = User::factory()->create();
        $this->upload($owner, 'valid.png')->assertOk();
        $path = $this->storedPath($owner);
        $bytes = Storage::disk('local')->get($path);

        foreach (['/storage/' . $path, '/' . $path, '/api/storage/' . $path] as $url) {
            $response = $this->get($url);
            $this->assertContains($response->getStatusCode(), [403, 404], "{$url} must not be publicly readable");
            $this->assertNotSame($bytes, $response->getContent());
        }
    }

    public function test_owner_can_view_own_picture_through_user_route(): void
    {
        $owner = User::factory()->create();
        $this->upload($owner, 'valid.png')->assertOk();

        $this->actingAs($owner, 'sanctum')->get("/api/users/{$owner->id}/profile-picture")->assertOk();
    }

    public function test_active_collaborators_on_a_shared_course_can_view_each_other(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $this->upload($owner, 'valid.png')->assertOk();
        $this->upload($member, 'valid.jpg')->assertOk();
        $course = $this->makeCourse($owner);
        $this->addCollaborator($course, $member);

        $this->actingAs($member, 'sanctum')->get("/api/users/{$owner->id}/profile-picture")->assertOk();
        $this->actingAs($owner, 'sanctum')->get("/api/users/{$member->id}/profile-picture")->assertOk();
    }

    public function test_two_collaborators_of_the_same_course_can_view_each_other(): void
    {
        $owner = User::factory()->create();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->upload($a, 'valid.png')->assertOk();
        $course = $this->makeCourse($owner);
        $this->addCollaborator($course, $a);
        $this->addCollaborator($course, $b);

        $this->actingAs($b, 'sanctum')->get("/api/users/{$a->id}/profile-picture")->assertOk();
    }

    public function test_pending_or_revoked_membership_does_not_grant_access(): void
    {
        $owner = User::factory()->create();
        $pending = User::factory()->create();
        $revoked = User::factory()->create();
        $this->upload($owner, 'valid.png')->assertOk();
        $course = $this->makeCourse($owner);
        $this->addCollaborator($course, $pending, CourseCollaborator::STATUS_PENDING);
        $this->addCollaborator($course, $revoked, CourseCollaborator::STATUS_REVOKED);

        $this->actingAs($pending, 'sanctum')->getJson("/api/users/{$owner->id}/profile-picture")->assertStatus(403);
        $this->actingAs($revoked, 'sanctum')->getJson("/api/users/{$owner->id}/profile-picture")->assertStatus(403);
    }

    public function test_admin_can_view_any_picture(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $this->upload($owner, 'valid.png')->assertOk();

        $this->actingAs($admin, 'sanctum')->get("/api/users/{$owner->id}/profile-picture")->assertOk();
    }

    public function test_viewing_a_user_without_picture_returns_404_not_a_leak(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $course = $this->makeCourse($owner);
        $this->addCollaborator($course, $member);

        $this->actingAs($member, 'sanctum')->getJson("/api/users/{$owner->id}/profile-picture")->assertStatus(404);
        $this->actingAs($member, 'sanctum')->getJson('/api/users/999999/profile-picture')->assertStatus(404);
    }

    public function test_collaboration_payloads_carry_avatar_urls_but_never_paths(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $this->upload($owner, 'valid.png')->assertOk();
        $course = $this->makeCourse($owner);
        $this->addCollaborator($course, $member);

        $response = $this->actingAs($member, 'sanctum')->getJson("/api/courses/{$course->id}/collaborators");

        $response->assertOk();
        $this->assertStringStartsWith("/api/users/{$owner->id}/profile-picture?v=", $response->json('data.owner.profile_picture_url'));
        $this->assertNull($response->json('data.collaborators.0.user.profile_picture_url'));
        $this->assertStringNotContainsString('profile-pictures/', $response->getContent());
    }
}
