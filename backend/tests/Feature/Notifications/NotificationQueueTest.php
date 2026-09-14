<?php

namespace Tests\Feature\Notifications;

use App\Jobs\StoreNotificationJob;
use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\InstitutionalReport;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\NotificationType;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STEP 47: queue integration. Notifications ride the project's queue (database in dev, Redis/Horizon in production);
 * this suite proves the job contract, retry idempotency, transaction safety, that the academic operation never
 * depends on notification delivery, and — when a Redis server is reachable — the Redis driver end-to-end.
 */
class NotificationQueueTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_notify_dispatches_store_job_on_the_configured_connection_and_queue(): void
    {
        Queue::fake();
        config(['notifications.queue.name' => 'notifications', 'notifications.queue.connection' => 'database']);

        app(NotificationService::class)->notify($this->user, NotificationType::SYSTEM_ALERT, ['title' => 't', 'message' => 'm', 'dedupe_key' => 'k']);

        Queue::assertPushed(StoreNotificationJob::class, function (StoreNotificationJob $job) {
            return $job->payload['type'] === 'SYSTEM_ALERT'
                && $job->payload['user_id'] === $this->user->id
                && $job->payload['dedupe_key'] === 'k'
                && $job->connection === 'database'
                && $job->queue === 'notifications'
                && $job->afterCommit === true
                && $job->tries === (int) config('notifications.queue.tries')
                && $job->backoff === config('notifications.queue.backoff')
                && $job->tags() === ['notification', 'type:SYSTEM_ALERT'];
        });
        $this->assertSame(0, Notification::count(), 'nothing is written until the worker runs');
    }

    public function test_queue_can_be_disabled_for_inline_writes(): void
    {
        Queue::fake();
        config(['notifications.queue.enabled' => false]);
        $n = app(NotificationService::class)->notify($this->user, NotificationType::SYSTEM_ALERT, ['title' => 't', 'message' => 'm']);
        Queue::assertNothingPushed();
        $this->assertNotNull($n);
        $this->assertSame(1, Notification::count());
    }

    public function test_database_queue_end_to_end_with_worker_and_redelivery(): void
    {
        config(['queue.default' => 'database', 'notifications.queue.connection' => 'database', 'notifications.queue.name' => 'default']);

        $service = app(NotificationService::class);
        $this->assertNull($service->notify($this->user, NotificationType::REPORT_GENERATED, ['title' => 'Report ready', 'message' => 'm', 'entity_type' => 'institutional_report', 'entity_id' => 3]));
        $this->assertSame(1, DB::table('jobs')->count(), 'job is parked in the jobs table');
        $this->assertSame(0, Notification::count());

        // Same event re-emitted (e.g. producer retry) → a second job, still one notification after both are worked
        $service->notify($this->user, NotificationType::REPORT_GENERATED, ['title' => 'Report ready', 'message' => 'm', 'entity_type' => 'institutional_report', 'entity_id' => 3]);
        $this->assertSame(2, DB::table('jobs')->count());

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, Notification::count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame('REPORT_GENERATED:institutional_report:3', Notification::first()->dedupe_key);
    }

    public function test_store_job_reports_permanent_failure_without_throwing(): void
    {
        $job = new StoreNotificationJob(['type' => 'SYSTEM_ALERT', 'user_id' => $this->user->id, 'dedupe_key' => 'x']);
        $job->failed(new \RuntimeException('db gone'));
        $this->assertSame(0, Notification::count());
        $this->assertTrue(true, 'failed() only logs');
    }

    public function test_notification_is_not_persisted_when_the_domain_transaction_rolls_back(): void
    {
        $service = app(NotificationService::class);
        try {
            DB::transaction(function () use ($service) {
                $service->notify($this->user, NotificationType::AI_ANALYSIS_COMPLETED, ['title' => 't', 'message' => 'm', 'entity_type' => 'analysis_report', 'entity_id' => 1]);
                throw new \RuntimeException('domain failure after the event fired');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, Notification::count(), 'afterCommit dispatch: rolled-back operations produce no notification');

        DB::transaction(fn () => $service->notify($this->user, NotificationType::AI_ANALYSIS_COMPLETED, ['title' => 't', 'message' => 'm', 'entity_type' => 'analysis_report', 'entity_id' => 2]));
        $this->assertSame(1, Notification::count(), 'committed operations do');
    }

    public function test_report_stays_completed_when_notification_storage_fails(): void
    {
        Storage::fake('local');
        $this->bindThrowingNotificationService();

        $course = Course::create(['user_id' => $this->user->id, 'course_code' => 'CSE101', 'course_name' => 'DB', 'semester' => 'Fall', 'academic_year' => '2026']);
        $assessment = Assessment::create(['course_id' => $course->id, 'title' => 'Midterm', 'type' => 'midterm', 'total_marks' => 10, 'status' => 'draft']);
        \App\Models\Question::create(['assessment_id' => $assessment->id, 'question_number' => 1, 'question_text' => 'Q', 'question_type' => 'descriptive', 'marks' => 10]);
        $report = InstitutionalReport::create(['report_uuid' => (string) Str::uuid(), 'created_by' => $this->user->id, 'report_type' => 'ASSESSMENT', 'scope_type' => 'ASSESSMENT', 'course_id' => $course->id, 'assessment_id' => $assessment->id, 'filters' => ['course_id' => $course->id, 'assessment_id' => $assessment->id], 'title' => 'R', 'format' => 'CSV', 'status' => 'PENDING', 'is_async' => true, 'contains_student_data' => false, 'record_count' => 1]);

        (new \App\Jobs\GenerateInstitutionalReportJob($report->id))->handle(app(\App\Services\InstitutionalReportService::class));

        $this->assertSame('COMPLETED', $report->fresh()->status, 'report generation must not depend on notification delivery');
        $this->assertNotNull($report->fresh()->file_path);
        $this->assertSame(0, Notification::count());
    }

    public function test_analysis_stays_completed_when_notification_storage_fails(): void
    {
        $this->bindThrowingNotificationService();
        $course = Course::create(['user_id' => $this->user->id, 'course_code' => 'CSE101', 'course_name' => 'DB', 'semester' => 'Fall', 'academic_year' => '2026']);
        $assessment = Assessment::create(['course_id' => $course->id, 'title' => 'Midterm', 'type' => 'midterm', 'total_marks' => 10, 'status' => 'draft']);

        $run = AnalysisReport::beginRun($assessment->id)->completeRun(['overall_score' => 70, 'total_questions' => 1, 'findings' => []]);

        $this->assertSame('completed', $run->fresh()->analysis_status);
        $this->assertTrue($run->fresh()->is_current);
        $this->assertSame(0, Notification::count());
    }

    public function test_redis_queue_end_to_end_when_a_redis_server_is_available(): void
    {
        $host = (string) config('database.redis.default.host', '127.0.0.1');
        $port = (int) config('database.redis.default.port', 6379);
        $socket = @fsockopen($host, $port, $errno, $errstr, 1);
        if (!$socket) {
            $this->markTestSkipped("No Redis server reachable on {$host}:{$port} — Redis pipeline is verified in the Docker production stack (worker: queue:work redis).");
        }
        fclose($socket);
        config(['database.redis.client' => 'predis', 'queue.default' => 'redis', 'notifications.queue.connection' => 'redis', 'notifications.queue.name' => 'facultylens-test-notifications']);

        try {
            \Illuminate\Support\Facades\Redis::connection()->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis reachable but not usable from PHP: ' . $e->getMessage());
        }
        \Illuminate\Support\Facades\Redis::connection()->del(['queues:facultylens-test-notifications']);

        $service = app(NotificationService::class);
        $service->notify($this->user, NotificationType::REPORT_GENERATED, ['title' => 'Report ready', 'message' => 'm', 'entity_type' => 'institutional_report', 'entity_id' => 5]);
        $service->notify($this->user, NotificationType::REPORT_GENERATED, ['title' => 'Report ready', 'message' => 'm', 'entity_type' => 'institutional_report', 'entity_id' => 5]);
        $this->assertSame(2, (int) \Illuminate\Support\Facades\Redis::connection()->llen('queues:facultylens-test-notifications'));

        $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'facultylens-test-notifications', '--once' => true, '--sleep' => 0])->assertSuccessful();
        $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'facultylens-test-notifications', '--once' => true, '--sleep' => 0])->assertSuccessful();

        $this->assertSame(1, Notification::count());
    }

    protected function bindThrowingNotificationService(): void
    {
        $throwing = new class(app(\App\Services\AuditLogService::class)) extends NotificationService {
            public function store(array $payload): ?Notification
            {
                throw new \RuntimeException('notifications table unavailable');
            }
        };
        $this->app->instance(NotificationService::class, $throwing);
    }
}
