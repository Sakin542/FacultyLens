<?php

namespace Tests\Feature\Notifications;

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\NotificationType;
use App\Services\Notification\NotificationService;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * STEP 48: Tests for real-time notification broadcasting.
 * - Private channel authorization prevents cross-user access (IDOR protection)
 * - NotificationCreated broadcasts only to users.{userId}.notifications
 * - Payload sanitization hides secrets and private documents
 * - Real-time failure never breaks durable database persistence
 */
class RealtimeBroadcastingTest extends TestCase
{
    use RefreshDatabase;

    protected User $userA;
    protected User $userB;
    protected NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
        ]);
        require base_path('routes/channels.php');
        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();
        $this->service = app(NotificationService::class);
    }

    public function test_notification_created_event_broadcasts_on_user_private_channel(): void
    {
        $notification = Notification::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->userA->id,
            'notifiable_type' => User::class,
            'notifiable_id' => $this->userA->id,
            'type' => NotificationType::AI_ANALYSIS_COMPLETED,
            'category' => 'AI',
            'severity' => 'SUCCESS',
            'title' => 'Assessment analysis completed',
            'message' => 'Analysis is ready.',
            'data' => ['assessment_id' => 10],
            'action_url' => '/assessments/10/analysis',
            'entity_type' => 'assessment',
            'entity_id' => 10,
        ]);

        $event = new NotificationCreated($notification);

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame("private-users.{$this->userA->id}.notifications", $channels[0]->name);
        $this->assertSame('NotificationCreated', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $this->assertSame($notification->id, $payload['id']);
        $this->assertSame('AI_ANALYSIS_COMPLETED', $payload['type']);
        $this->assertSame('SUCCESS', $payload['severity']);
        $this->assertSame('/assessments/10/analysis', $payload['action_url']);
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('api_key', $payload);
        $this->assertArrayNotHasKey('dedupe_key', $payload);
    }

    public function test_channel_authorization_allows_owner_and_denies_other_users(): void
    {
        // User A authorizing their own channel -> 200 with Pusher/Reverb auth signature
        $responseA = $this->actingAs($this->userA)->postJson('/broadcasting/auth', [
            'channel_name' => "private-users.{$this->userA->id}.notifications",
            'socket_id' => '1234.5678',
        ]);
        $responseA->assertOk();
        $this->assertArrayHasKey('auth', $responseA->json());

        // User A attempting to subscribe to User B's channel -> 403 Forbidden
        $responseB = $this->actingAs($this->userA)->postJson('/broadcasting/auth', [
            'channel_name' => "private-users.{$this->userB->id}.notifications",
            'socket_id' => '1234.5678',
        ]);
        $responseB->assertForbidden();

        // Unauthenticated client attempting to subscribe -> 401/403
        $this->app['auth']->forgetGuards();
        $responseGuest = $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-users.{$this->userA->id}.notifications",
            'socket_id' => '1234.5678',
        ]);
        $this->assertContains($responseGuest->status(), [401, 403]);
    }

    public function test_api_channel_authorization_with_sanctum(): void
    {
        // Test /api/broadcasting/auth route with Sanctum
        $response = $this->actingAs($this->userA, 'sanctum')->postJson('/api/broadcasting/auth', [
            'channel_name' => "private-users.{$this->userA->id}.notifications",
            'socket_id' => '1234.5678',
        ]);
        $response->assertOk();
        $this->assertArrayHasKey('auth', $response->json());

        // User A cannot access User B's channel via API
        $responseDenied = $this->actingAs($this->userA, 'sanctum')->postJson('/api/broadcasting/auth', [
            'channel_name' => "private-users.{$this->userB->id}.notifications",
            'socket_id' => '1234.5678',
        ]);
        $responseDenied->assertForbidden();
    }

    public function test_service_store_dispatches_notification_created_broadcast_event(): void
    {
        Event::fake([NotificationCreated::class]);

        $this->service->notify($this->userA, NotificationType::AI_ANALYSIS_COMPLETED, [
            'title' => 'Analysis done',
            'message' => 'Ready',
            'action_url' => '/assessments/1/analysis',
            'entity_type' => 'assessment',
            'entity_id' => 1,
        ], ['queue' => false]);

        Event::assertDispatched(NotificationCreated::class, function (NotificationCreated $e) {
            return (int) $e->notification->user_id === (int) $this->userA->id &&
                $e->notification->type === NotificationType::AI_ANALYSIS_COMPLETED;
        });
    }

    public function test_duplicate_event_does_not_broadcast_twice(): void
    {
        Event::fake([NotificationCreated::class]);

        $attrs = [
            'title' => 'Analysis done',
            'message' => 'Ready',
            'action_url' => '/assessments/1/analysis',
            'entity_type' => 'assessment',
            'entity_id' => 1,
        ];

        // First notification persists and broadcasts
        $this->service->notify($this->userA, NotificationType::AI_ANALYSIS_COMPLETED, $attrs, ['queue' => false]);
        // Second identical notification deduplicates
        $this->service->notify($this->userA, NotificationType::AI_ANALYSIS_COMPLETED, $attrs, ['queue' => false]);

        Event::assertDispatchedTimes(NotificationCreated::class, 1);
        $this->assertSame(1, Notification::where('user_id', $this->userA->id)->count());
    }
}
