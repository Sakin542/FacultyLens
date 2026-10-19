<?php

namespace Tests\Feature;

use App\Models\CollaborationComment;
use App\Models\Course;
use App\Models\CourseCollaborator;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Collaboration hub data (GET /api/collaboration/summary) and owner notification for new discussion threads.
 */
class CollaborationWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $editor;
    protected User $outsider;
    protected Course $course;
    protected Course $soloCourse;

    protected function setUp(): void
    {
        parent::setUp();
        NotificationFacade::fake();

        $this->owner = User::factory()->create(['name' => 'Dr. Owner', 'email' => 'owner@university.edu']);
        $this->editor = User::factory()->create(['name' => 'Dr. Editor', 'email' => 'editor@university.edu']);
        $this->outsider = User::factory()->create(['name' => 'Dr. Outsider', 'email' => 'outsider@university.edu']);

        $this->course = Course::create(['user_id' => $this->owner->id, 'course_code' => 'CSE401', 'course_name' => 'Artificial Intelligence', 'semester' => 'Fall', 'academic_year' => '2026']);
        $this->soloCourse = Course::create(['user_id' => $this->owner->id, 'course_code' => 'CSE402', 'course_name' => 'Machine Learning', 'semester' => 'Fall', 'academic_year' => '2026']);
        Course::create(['user_id' => $this->outsider->id, 'course_code' => 'EEE201', 'course_name' => 'Circuits', 'semester' => 'Fall', 'academic_year' => '2026']);

        CourseCollaborator::create(['course_id' => $this->course->id, 'user_id' => $this->editor->id, 'invited_by' => $this->owner->id, 'role' => 'EDITOR', 'status' => 'ACTIVE', 'accepted_at' => now()]);
    }

    public function test_summary_lists_owned_courses_with_team_size_invitations_and_discussions(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/courses/{$this->soloCourse->id}/collaborators/invite", ['email' => 'new@university.edu', 'role' => 'REVIEWER'])->assertStatus(201);
        $this->postJson("/api/courses/{$this->course->id}/comments", ['commentable_type' => 'course', 'commentable_id' => $this->course->id, 'body' => 'Plan for the midterm?'])->assertStatus(201);

        $data = $this->getJson('/api/collaboration/summary')->assertOk()->json('data');

        $owned = collect($data['owned_courses'])->keyBy('course_code');
        $this->assertSame(['CSE401', 'CSE402'], $owned->keys()->all(), 'only the user\'s own courses, sorted by code');
        $this->assertSame(1, $owned['CSE401']['collaborators_count']);
        $this->assertSame(1, $owned['CSE401']['open_discussions_count']);
        $this->assertSame(0, $owned['CSE402']['collaborators_count']);
        $this->assertSame(1, $owned['CSE402']['pending_invitations_count']);

        $this->assertCount(1, $data['sent_invitations']);
        $this->assertSame('new@university.edu', $data['sent_invitations'][0]['invited_email']);
        $this->assertArrayNotHasKey('token_hash', $data['sent_invitations'][0]);
        $this->assertSame(0, $data['shared_courses_count'], 'owning a course is not "shared with me"');
    }

    public function test_summary_lists_shared_courses_with_owner_and_role_for_a_collaborator(): void
    {
        Sanctum::actingAs($this->editor);
        $data = $this->getJson('/api/collaboration/summary')->assertOk()->json('data');

        $this->assertSame(1, $data['shared_courses_count']);
        $shared = $data['shared_courses'][0];
        $this->assertSame('CSE401', $shared['course_code']);
        $this->assertSame('EDITOR', $shared['role']);
        $this->assertSame('Dr. Owner', $shared['owner']['name']);
        $this->assertSame([], $data['owned_courses']);
        $this->assertSame([], $data['sent_invitations'], 'collaborators never see the owner\'s outgoing invitations');
    }

    public function test_outsider_summary_never_includes_other_peoples_courses(): void
    {
        Sanctum::actingAs($this->outsider);
        $data = $this->getJson('/api/collaboration/summary')->assertOk()->json('data');

        $this->assertSame(['EEE201'], collect($data['owned_courses'])->pluck('course_code')->all());
        $this->assertSame([], $data['shared_courses']);
        $this->assertSame(0, $data['unresolved_discussions_count']);
    }

    public function test_new_discussion_thread_notifies_the_course_owner_but_not_the_author(): void
    {
        Sanctum::actingAs($this->editor);
        $this->postJson("/api/courses/{$this->course->id}/comments", ['commentable_type' => 'course', 'commentable_id' => $this->course->id, 'body' => 'Could we add a Bayes rule question?'])->assertStatus(201);

        $this->assertSame(1, Notification::where('user_id', $this->owner->id)->where('type', 'COMMENT_CREATED')->count());
        $this->assertSame(0, Notification::where('user_id', $this->editor->id)->where('type', 'COMMENT_CREATED')->count());
    }

    public function test_owner_starting_a_thread_does_not_notify_themselves(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/courses/{$this->course->id}/comments", ['commentable_type' => 'course', 'commentable_id' => $this->course->id, 'body' => 'Team: please review CLO2.'])->assertStatus(201);

        $this->assertSame(1, CollaborationComment::count());
        $this->assertSame(0, Notification::where('user_id', $this->owner->id)->where('type', 'COMMENT_CREATED')->count());
    }
}
