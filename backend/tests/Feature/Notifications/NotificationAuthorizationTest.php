<?php

namespace Tests\Feature\Notifications;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\InstitutionalReport;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 47: ownership / IDOR. User A can never read, mutate or even confirm the existence of User B's notifications,
 * and following a notification's action_url is re-authorized by the target resource.
 */
class NotificationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $a;
    protected User $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = User::factory()->create(['name' => 'Faculty A']);
        $this->b = User::factory()->create(['name' => 'Faculty B']);
    }

    public function test_user_a_cannot_access_user_b_notifications_by_id(): void
    {
        $theirs = Notification::factory()->for($this->b)->create();
        Sanctum::actingAs($this->a);

        $this->getJson("/api/notifications/{$theirs->id}")->assertStatus(404);
        $this->postJson("/api/notifications/{$theirs->id}/read")->assertStatus(404);
        $this->postJson("/api/notifications/{$theirs->id}/dismiss")->assertStatus(404);
        $this->deleteJson("/api/notifications/{$theirs->id}")->assertStatus(404);

        $theirs->refresh();
        $this->assertNull($theirs->read_at);
        $this->assertNull($theirs->dismissed_at);
        $this->assertDatabaseHas('notifications', ['id' => $theirs->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'NOTIFICATION_READ']);
    }

    public function test_listing_unread_count_and_read_all_are_scoped_to_the_caller(): void
    {
        Notification::factory()->for($this->a)->count(2)->create();
        Notification::factory()->for($this->b)->count(3)->create();

        Sanctum::actingAs($this->a);
        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.unread_count', 2);
        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', 2);
        $this->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 2);

        Sanctum::actingAs($this->b);
        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', 3, 'read-all must not touch other users');
    }

    public function test_manipulated_ids_and_query_parameters_cannot_widen_scope(): void
    {
        Notification::factory()->for($this->b)->count(2)->create();
        Sanctum::actingAs($this->a);

        $this->getJson('/api/notifications?user_id=' . $this->b->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/notifications?notifiable_id=' . $this->b->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/notifications/' OR 1=1 --")->assertStatus(404);
        $this->getJson('/api/notifications/' . Str::uuid())->assertStatus(404);
    }

    public function test_preferences_are_per_user(): void
    {
        Sanctum::actingAs($this->a);
        $this->patchJson('/api/notification-preferences/AI_ANALYSIS_COMPLETED', ['in_app_enabled' => false])->assertOk();
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $this->a->id, 'notification_type' => 'AI_ANALYSIS_COMPLETED', 'in_app_enabled' => false]);
        $this->assertDatabaseMissing('notification_preferences', ['user_id' => $this->b->id]);

        Sanctum::actingAs($this->b);
        $row = collect($this->getJson('/api/notification-preferences')->json('data.preferences'))->firstWhere('notification_type', 'AI_ANALYSIS_COMPLETED');
        $this->assertTrue($row['in_app_enabled']);
    }

    public function test_service_refuses_to_act_on_another_users_row(): void
    {
        $theirs = Notification::factory()->for($this->b)->create();
        $service = app(\App\Services\Notification\NotificationService::class);

        $this->assertNull($service->findForUser($this->a, $theirs->id));
        try {
            $service->markAsRead($this->a, $theirs);
            $this->fail('expected 404');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_a_notification_does_not_grant_access_to_its_target(): void
    {
        $course = Course::create(['user_id' => $this->b->id, 'course_code' => 'CSE101', 'course_name' => 'Database Systems', 'semester' => 'Fall', 'academic_year' => '2026']);
        $assessment = Assessment::create(['course_id' => $course->id, 'title' => 'Midterm', 'type' => 'midterm', 'total_marks' => 30, 'status' => 'draft']);
        $report = InstitutionalReport::create(['report_uuid' => (string) Str::uuid(), 'created_by' => $this->b->id, 'report_type' => 'ASSESSMENT', 'scope_type' => 'ASSESSMENT', 'course_id' => $course->id, 'assessment_id' => $assessment->id, 'filters' => [], 'title' => 'R', 'format' => 'PDF', 'status' => 'COMPLETED', 'is_async' => false, 'contains_student_data' => false, 'record_count' => 1]);

        // Even if A somehow held a notification pointing at B's resources (e.g. mis-addressed by a bug), the pages re-authorize.
        Notification::factory()->for($this->a)->ofType(NotificationType::AI_ANALYSIS_COMPLETED)->create(['action_url' => "/assessments/{$assessment->id}/analysis", 'entity_type' => 'assessment', 'entity_id' => $assessment->id]);
        Notification::factory()->for($this->a)->ofType(NotificationType::REPORT_GENERATED)->create(['action_url' => "/reports/{$report->id}", 'entity_type' => 'institutional_report', 'entity_id' => $report->id]);

        Sanctum::actingAs($this->a);
        $this->getJson("/api/assessments/{$assessment->id}")->assertStatus(403);
        $this->getJson("/api/ai/assessments/{$assessment->id}/analysis")->assertStatus(403);
        $this->getJson("/api/reports/{$report->id}")->assertStatus(403);
        $this->getJson("/api/reports/{$report->id}/download")->assertStatus(403);
    }

    public function test_recipient_resolution_follows_the_collaboration_matrix(): void
    {
        $course = Course::create(['user_id' => $this->a->id, 'course_code' => 'CSE101', 'course_name' => 'Database Systems', 'semester' => 'Fall', 'academic_year' => '2026']);
        $editor = User::factory()->create();
        $viewer = User::factory()->create();
        $pending = User::factory()->create();
        \App\Models\CourseCollaborator::create(['course_id' => $course->id, 'user_id' => $editor->id, 'invited_by' => $this->a->id, 'role' => 'EDITOR', 'status' => 'ACTIVE', 'accepted_at' => now()]);
        \App\Models\CourseCollaborator::create(['course_id' => $course->id, 'user_id' => $viewer->id, 'invited_by' => $this->a->id, 'role' => 'VIEWER', 'status' => 'ACTIVE', 'accepted_at' => now()]);
        \App\Models\CourseCollaborator::create(['course_id' => $course->id, 'user_id' => $pending->id, 'invited_by' => $this->a->id, 'role' => 'EDITOR', 'status' => 'PENDING']);

        $resolver = app(\App\Services\Notification\NotificationRecipientResolver::class);
        $this->assertEqualsCanonicalizing([$this->a->id, $editor->id, $viewer->id], $resolver->forCourse($course, 'view_analysis')->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$this->a->id, $editor->id], $resolver->forCourse($course, 'view_student_data')->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$editor->id], $resolver->forCourse($course, 'run_analysis', $this->a->id)->pluck('id')->all());
        $this->assertNotContains($pending->id, $resolver->forCourse($course, 'view')->pluck('id')->all(), 'pending members are not recipients');
        $this->assertNotContains($this->b->id, $resolver->forCourse($course, 'view')->pluck('id')->all());
        $this->assertSame([], $resolver->forCourse(null, 'view')->all());
    }
}
