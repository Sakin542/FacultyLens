<?php

namespace Tests\Feature\Notifications;

use App\Jobs\StoreNotificationJob;
use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\NotificationType;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STEP 47: the same event must never yield two notifications for one user — whether the producer fires twice,
 * the queue job is retried, or two workers race on the insert.
 */
class NotificationDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->service = app(NotificationService::class);
    }

    public function test_same_type_entity_and_id_collapse_to_one_row(): void
    {
        $attrs = ['title' => 'Assessment analysis completed', 'message' => 'x', 'entity_type' => 'analysis_report', 'entity_id' => 82, 'data' => ['assessment_id' => 15, 'analysis_id' => 82]];
        $first = $this->service->notify($this->user, NotificationType::AI_ANALYSIS_COMPLETED, $attrs);
        $second = $this->service->notify($this->user, NotificationType::AI_ANALYSIS_COMPLETED, $attrs + ['title' => 'Different title, same event']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Notification::count());
        $this->assertSame('Assessment analysis completed', $first->fresh()->title, 'the first delivery wins');
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'NOTIFICATION_CREATED')->count());
    }

    public function test_different_entity_ids_are_distinct(): void
    {
        $this->service->notify($this->user, NotificationType::AI_ANALYSIS_COMPLETED, ['title' => 'a', 'message' => 'x', 'entity_type' => 'analysis_report', 'entity_id' => 82]);
        $this->service->notify($this->user, NotificationType::AI_ANALYSIS_COMPLETED, ['title' => 'a', 'message' => 'x', 'entity_type' => 'analysis_report', 'entity_id' => 83]);
        $this->service->notify($this->user, NotificationType::AI_ANALYSIS_FAILED, ['title' => 'a', 'message' => 'x', 'entity_type' => 'analysis_report', 'entity_id' => 82]);
        $this->assertSame(3, Notification::count());
    }

    public function test_identical_title_and_message_without_a_key_are_not_deduplicated_by_text(): void
    {
        $this->service->notify($this->user, NotificationType::SYSTEM_ALERT, ['title' => 'Maintenance', 'message' => 'Tonight']);
        $this->service->notify($this->user, NotificationType::SYSTEM_ALERT, ['title' => 'Maintenance', 'message' => 'Tonight']);
        $this->assertSame(2, Notification::count(), 'text comparison is never the dedupe mechanism');
    }

    public function test_dedupe_is_per_user(): void
    {
        $other = User::factory()->create();
        $attrs = ['title' => 'a', 'message' => 'x', 'entity_type' => 'report', 'entity_id' => 1];
        $this->service->notify($this->user, NotificationType::REPORT_GENERATED, $attrs);
        $this->service->notify($other, NotificationType::REPORT_GENERATED, $attrs);
        $this->assertSame(2, Notification::count());
    }

    public function test_retried_job_with_the_same_payload_is_idempotent(): void
    {
        $payload = $this->service->buildPayload($this->user->id, NotificationType::REPORT_GENERATED, ['title' => 'Report ready', 'message' => 'x', 'entity_type' => 'institutional_report', 'entity_id' => 9]);

        $job = new StoreNotificationJob($payload);
        $job->handle($this->service);
        $job->handle($this->service); // worker retry after a crash between insert and ack
        (new StoreNotificationJob($payload))->handle($this->service); // redelivered copy

        $this->assertSame(1, Notification::count());
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'NOTIFICATION_CREATED')->count());
    }

    public function test_database_unique_constraint_backs_the_race(): void
    {
        $payload = $this->service->buildPayload($this->user->id, NotificationType::REPORT_GENERATED, ['title' => 'Report ready', 'message' => 'x', 'dedupe_key' => 'race']);
        $this->service->store($payload);

        // Bypass the pre-check and insert the duplicate directly: the unique index must reject it.
        $this->expectException(\Illuminate\Database\QueryException::class);
        Notification::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $this->user->id, 'notifiable_type' => User::class, 'notifiable_id' => $this->user->id,
            'type' => 'REPORT_GENERATED', 'category' => 'REPORT', 'severity' => 'SUCCESS', 'title' => 'dup', 'message' => 'dup', 'data' => [], 'dedupe_key' => 'race']);
    }

    public function test_analysis_completion_fired_twice_notifies_once_per_recipient(): void
    {
        $course = Course::create(['user_id' => $this->user->id, 'course_code' => 'CSE101', 'course_name' => 'DB', 'semester' => 'Fall', 'academic_year' => '2026']);
        $assessment = Assessment::create(['course_id' => $course->id, 'title' => 'Midterm', 'type' => 'midterm', 'total_marks' => 30, 'status' => 'draft']);
        $run = AnalysisReport::beginRun($assessment->id);

        $run->completeRun(['overall_score' => 80, 'total_questions' => 1, 'findings' => []]);
        event(new \App\Events\AssessmentAnalysisCompleted($run)); // duplicate producer call / replay

        $this->assertSame(1, Notification::where('user_id', $this->user->id)->where('type', 'AI_ANALYSIS_COMPLETED')->count());

        // A brand-new run is a new event
        $run2 = AnalysisReport::beginRun($assessment->id);
        $run2->completeRun(['overall_score' => 81, 'total_questions' => 1, 'findings' => []]);
        $this->assertSame(2, Notification::where('user_id', $this->user->id)->where('type', 'AI_ANALYSIS_COMPLETED')->count());
    }
}
