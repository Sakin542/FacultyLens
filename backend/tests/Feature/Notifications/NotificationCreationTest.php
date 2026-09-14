<?php

namespace Tests\Feature\Notifications;

use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\NotificationCategory;
use App\Notifications\NotificationSeverity;
use App\Notifications\NotificationType;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * STEP 47: NotificationService creation contract — registry validation, payload sanitisation (no secrets / private
 * content), URL policy, expiration defaults, preference gating and the model's serialisation.
 */
class NotificationCreationTest extends TestCase
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

    public function test_registry_is_consistent(): void
    {
        $this->assertSame(['AI', 'ASSESSMENT', 'COLLABORATION', 'REVIEW', 'GRADING', 'PERFORMANCE', 'REPORT', 'FEEDBACK', 'SECURITY', 'SYSTEM'], NotificationCategory::all());
        $this->assertSame(['INFO', 'SUCCESS', 'WARNING', 'ERROR', 'CRITICAL'], NotificationSeverity::all());
        $required = ['AI_ANALYSIS_COMPLETED', 'AI_ANALYSIS_FAILED', 'AI_RECOMMENDATION_CREATED', 'RUBRIC_GENERATED', 'RUBRIC_GENERATION_FAILED', 'QUESTION_GENERATION_COMPLETED', 'QUESTION_GENERATION_FAILED',
            'ASSESSMENT_VERSION_CREATED', 'ASSESSMENT_VERSION_APPROVED', 'ASSESSMENT_VERSION_FINALIZED', 'ASSESSMENT_VERSION_ARCHIVED',
            'COLLABORATION_INVITATION', 'COLLABORATION_ACCEPTED', 'COLLABORATION_REJECTED', 'COLLABORATION_REMOVED', 'COMMENT_CREATED', 'MENTION_RECEIVED',
            'REVIEW_ASSIGNED', 'REVIEW_COMPLETED', 'GRADING_COMPLETED', 'INTER_GRADER_REVIEW_REQUIRED', 'PERFORMANCE_ANALYSIS_COMPLETED', 'LEARNING_GAP_DETECTED',
            'REPORT_GENERATED', 'REPORT_GENERATION_FAILED', 'FACULTY_FEEDBACK_RECEIVED', 'SECURITY_ALERT', 'SYSTEM_ALERT'];
        foreach ($required as $type) {
            $this->assertTrue(NotificationType::isValid($type), $type);
            $this->assertTrue(NotificationCategory::isValid(NotificationType::category($type)), "$type category");
            $this->assertTrue(NotificationSeverity::isValid(NotificationType::defaultSeverity($type)), "$type severity");
            $this->assertSame($type, constant(NotificationType::class . '::' . $type));
        }
        $this->assertSame('SUCCESS', NotificationType::defaultSeverity('AI_ANALYSIS_COMPLETED'));
        $this->assertSame('ERROR', NotificationType::defaultSeverity('AI_ANALYSIS_FAILED'));
        $this->assertSame('WARNING', NotificationType::defaultSeverity('LEARNING_GAP_DETECTED'));
        $this->assertSame('CRITICAL', NotificationType::defaultSeverity('SECURITY_ALERT'));
        $this->assertTrue(NotificationCategory::isMandatory('SECURITY'));
        $this->assertTrue(NotificationCategory::isMandatory('SYSTEM'));
        $this->assertFalse(NotificationCategory::isMandatory('AI'));
    }

    public function test_creates_a_notification_with_derived_category_severity_and_dedupe_key(): void
    {
        $n = $this->service->notify($this->user, NotificationType::REPORT_GENERATED, [
            'title' => 'Report ready', 'message' => 'Your report is ready.', 'action_url' => '/reports/7', 'entity_type' => 'InstitutionalReport', 'entity_id' => 7,
            'data' => ['report_id' => 7, 'format' => 'PDF'],
        ]);

        $this->assertInstanceOf(Notification::class, $n);
        $this->assertSame('REPORT', $n->category);
        $this->assertSame('SUCCESS', $n->severity);
        $this->assertSame('institutional_report', $n->entity_type);
        $this->assertSame('REPORT_GENERATED:institutional_report:7', $n->dedupe_key);
        $this->assertSame($this->user->id, $n->user_id);
        $this->assertSame($this->user->id, $n->notifiable_id);
        $this->assertTrue($n->isUnread());
        $this->assertFalse($n->isDismissed());
        $this->assertSame(['report_id' => 7, 'format' => 'PDF'], $n->data);
        $this->assertSame($n->id, $this->user->notifications()->first()->id, 'User::notifications() uses the FacultyLens model');
        $this->assertSame(1, $this->user->unreadNotifications()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'NOTIFICATION_CREATED', 'entity_type' => 'Notification']);
    }

    public function test_rejects_unknown_type_category_or_severity_and_empty_text(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->notify($this->user, 'NOT_A_TYPE', ['title' => 'x', 'message' => 'y']);
    }

    public function test_rejects_invalid_severity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->notify($this->user, NotificationType::SYSTEM_ALERT, ['title' => 'x', 'message' => 'y', 'severity' => 'URGENT']);
    }

    public function test_rejects_missing_title_or_message(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->notify($this->user, NotificationType::SYSTEM_ALERT, ['title' => '  ', 'message' => 'y']);
    }

    public function test_rejects_unknown_recipient(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->notify(999999, NotificationType::SYSTEM_ALERT, ['title' => 'x', 'message' => 'y']);
    }

    public function test_payload_never_stores_secrets_or_private_content(): void
    {
        $n = $this->service->notify($this->user, NotificationType::SYSTEM_ALERT, [
            'title' => 'x', 'message' => 'y', 'dedupe_key' => 'privacy',
            'data' => [
                'assessment_id' => 15, 'api_key' => 'sk-123', 'token' => 'abc', 'password' => 'p', 'hf_token' => 'h', 'secret_value' => 's', 'authorization' => 'Bearer x',
                'answer_text' => 'student wrote…', 'original_answer_text' => 'x', 'extracted_text' => 'document body', 'system_prompt' => 'You are…', 'email' => 'a@b.c',
                'nested' => ['ok' => 1, 'token' => 'nested-secret', 'deeper' => ['too' => 'deep']],
                'long' => str_repeat('a', 2000),
                'object' => new \stdClass(),
            ],
        ]);

        $json = json_encode($n->fresh()->data);
        foreach (['sk-123', 'abc', 'student wrote', 'document body', 'You are', 'a@b.c', 'nested-secret', 'Bearer'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json, "payload leaked [$forbidden]");
        }
        $this->assertSame(15, $n->data['assessment_id']);
        $this->assertSame(['ok' => 1], $n->data['nested']);
        $this->assertSame(NotificationService::MAX_DATA_STRING, strlen($n->data['long']));
        $this->assertArrayNotHasKey('object', $n->data);
    }

    public function test_action_url_must_be_an_app_relative_path(): void
    {
        $mk = fn ($url) => $this->service->notify($this->user, NotificationType::SYSTEM_ALERT, ['title' => 'x', 'message' => 'y', 'action_url' => $url, 'dedupe_key' => 'u' . md5((string) $url)])->action_url;

        $this->assertSame('/assessments/1/analysis', $mk('/assessments/1/analysis'));
        $this->assertNull($mk('https://evil.example/phish'));
        $this->assertNull($mk('//evil.example/phish'));
        $this->assertNull($mk('javascript:alert(1)'));
        $this->assertSame('/courses/5', $mk(rtrim(config('notifications.frontend_url'), '/') . '/courses/5'));
    }

    public function test_default_expiry_and_severity_override(): void
    {
        $inv = $this->service->notify($this->user, NotificationType::COLLABORATION_INVITATION, ['title' => 'x', 'message' => 'y', 'dedupe_key' => 'inv']);
        $this->assertNotNull($inv->expires_at);
        $this->assertTrue($inv->expires_at->between(now()->addDays(6), now()->addDays(8)));

        $warn = $this->service->notify($this->user, NotificationType::AI_ANALYSIS_COMPLETED, ['title' => 'x', 'message' => 'y', 'severity' => 'warning', 'dedupe_key' => 'w']);
        $this->assertSame('WARNING', $warn->severity);
        $this->assertNull($warn->expires_at);
    }

    public function test_user_preference_suppresses_non_mandatory_types_only(): void
    {
        NotificationPreference::create(['user_id' => $this->user->id, 'notification_type' => NotificationType::AI_ANALYSIS_COMPLETED, 'in_app_enabled' => false]);
        NotificationPreference::create(['user_id' => $this->user->id, 'notification_type' => NotificationType::SECURITY_ALERT, 'in_app_enabled' => false]);

        $this->assertNull($this->service->notify($this->user, NotificationType::AI_ANALYSIS_COMPLETED, ['title' => 'x', 'message' => 'y', 'dedupe_key' => 'a']));
        $this->assertNotNull($this->service->notify($this->user, NotificationType::SECURITY_ALERT, ['title' => 'x', 'message' => 'y', 'dedupe_key' => 's']));
        $this->assertNotNull($this->service->notify($this->user, NotificationType::AI_ANALYSIS_FAILED, ['title' => 'x', 'message' => 'y', 'dedupe_key' => 'f']));
        $this->assertSame(2, Notification::count());
    }

    public function test_notify_many_deduplicates_recipients_and_reports_count(): void
    {
        $other = User::factory()->create();
        $n = $this->service->notifyMany([$this->user, $this->user->id, $other, 0], NotificationType::SYSTEM_ALERT, ['title' => 'x', 'message' => 'y']);
        $this->assertSame(2, $n);
        $this->assertSame(2, Notification::count());
    }

    public function test_dispatch_failure_never_throws_to_the_caller(): void
    {
        config(['notifications.queue.connection' => 'does-not-exist']);
        $result = $this->service->notify($this->user, NotificationType::SYSTEM_ALERT, ['title' => 'x', 'message' => 'y']);
        $this->assertNull($result);
        $this->assertSame(0, Notification::count());
    }

    public function test_api_shape_hides_internal_columns(): void
    {
        $n = Notification::factory()->for($this->user)->create();
        $api = $n->toApi();
        $this->assertArrayNotHasKey('dedupe_key', $api);
        $this->assertArrayNotHasKey('notifiable_type', $api);
        $this->assertArrayNotHasKey('user_id', $api);
        $this->assertArrayNotHasKey('dedupe_key', $n->toArray());
    }
}
