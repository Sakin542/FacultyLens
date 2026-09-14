<?php

namespace Tests\Feature\Notifications;

use App\Models\Notification;
use App\Models\User;
use App\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 47: notification center API — listing, pagination, filters, unread count, read / read-all / dismiss / delete,
 * expiration and audit trail.
 */
class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    protected function login(): void
    {
        Sanctum::actingAs($this->user);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/notifications')->assertStatus(401);
        $this->getJson('/api/notifications/unread-count')->assertStatus(401);
        $this->getJson('/api/notification-preferences')->assertStatus(401);
    }

    public function test_lists_own_active_notifications_newest_first_with_unread_count(): void
    {
        $this->login();
        Notification::factory()->for($this->user)->count(3)->sequence(
            ['created_at' => now()->subMinutes(3), 'title' => 'oldest'],
            ['created_at' => now()->subMinutes(2), 'title' => 'middle'],
            ['created_at' => now()->subMinute(), 'title' => 'newest'],
        )->create();
        Notification::factory()->for($this->user)->read()->create(['title' => 'already read', 'created_at' => now()->subMinutes(10)]);
        Notification::factory()->for($this->user)->dismissed()->create(['title' => 'dismissed']);
        Notification::factory()->for($this->user)->expired()->create(['title' => 'expired']);
        Notification::factory()->create(['title' => 'someone else']);

        $res = $this->getJson('/api/notifications')->assertOk();
        $titles = collect($res->json('data'))->pluck('title')->all();
        $this->assertSame(['newest', 'middle', 'oldest', 'already read'], array_values(array_diff($titles, [])));
        $this->assertNotContains('dismissed', $titles);
        $this->assertNotContains('expired', $titles);
        $this->assertNotContains('someone else', $titles);
        $res->assertJsonPath('meta.total', 4)->assertJsonPath('meta.unread_count', 3)->assertJsonPath('meta.per_page', 20);
        $this->assertGreaterThanOrEqual(30, $res->json('meta.poll_interval_seconds'));

        $row = $res->json('data.0');
        foreach (['id', 'type', 'category', 'severity', 'title', 'message', 'data', 'action_url', 'entity_type', 'entity_id', 'read_at', 'dismissed_at', 'expires_at', 'created_at'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
        $this->assertArrayNotHasKey('dedupe_key', $row);
        $this->assertArrayNotHasKey('notifiable_id', $row);
    }

    public function test_pagination_defaults_and_caps(): void
    {
        $this->login();
        Notification::factory()->for($this->user)->count(60)->create();

        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.last_page', 3);
        $this->getJson('/api/notifications?per_page=50&page=2')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.current_page', 2);
        $this->getJson('/api/notifications?per_page=500')->assertStatus(422);
        $this->getJson('/api/notifications?per_page=0')->assertStatus(422);
    }

    public function test_filters_are_applied_server_side(): void
    {
        $this->login();
        Notification::factory()->for($this->user)->ofType(NotificationType::AI_ANALYSIS_COMPLETED)->create();
        Notification::factory()->for($this->user)->ofType(NotificationType::REPORT_GENERATED)->read()->create();
        Notification::factory()->for($this->user)->ofType(NotificationType::COLLABORATION_INVITATION)->create();
        Notification::factory()->for($this->user)->ofType(NotificationType::SECURITY_ALERT)->create(['created_at' => now()->subDays(10)]);

        $this->getJson('/api/notifications?filter=unread')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/notifications?filter=AI')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.category', 'AI');
        $this->getJson('/api/notifications?filter=report')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'REPORT_GENERATED');
        $this->getJson('/api/notifications?filter=COLLABORATION')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/notifications?filter=SECURITY')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/notifications?filter=all')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/notifications?filter=NOPE')->assertOk()->assertJsonCount(4, 'data'); // unknown filter → all
        $this->getJson('/api/notifications?category=NOPE')->assertStatus(422);
        $this->getJson('/api/notifications?from=' . now()->subDay()->toDateString())->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/notifications?unread=1&filter=AI')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_unread_count_endpoint_excludes_read_dismissed_and_expired(): void
    {
        $this->login();
        Notification::factory()->for($this->user)->count(2)->create();
        Notification::factory()->for($this->user)->read()->create();
        Notification::factory()->for($this->user)->dismissed()->create();
        Notification::factory()->for($this->user)->expired()->create();
        Notification::factory()->create();

        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', 2);
    }

    public function test_mark_read_read_all_dismiss_delete_and_show(): void
    {
        $this->login();
        $a = Notification::factory()->for($this->user)->create();
        $b = Notification::factory()->for($this->user)->create();
        $c = Notification::factory()->for($this->user)->create();

        $this->getJson("/api/notifications/{$a->id}")->assertOk()->assertJsonPath('data.id', $a->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'NOTIFICATION_VIEWED', 'user_id' => $this->user->id]);

        $this->postJson("/api/notifications/{$a->id}/read")->assertOk()->assertJsonPath('meta.unread_count', 2);
        $this->assertNotNull($a->fresh()->read_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'NOTIFICATION_READ', 'user_id' => $this->user->id]);
        // idempotent
        $this->postJson("/api/notifications/{$a->id}/read")->assertOk();
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'NOTIFICATION_READ')->count());

        $this->postJson("/api/notifications/{$b->id}/dismiss")->assertOk()->assertJsonPath('meta.unread_count', 1);
        $b->refresh();
        $this->assertNotNull($b->dismissed_at);
        $this->assertNotNull($b->read_at, 'dismissing also clears the unread state');
        $this->assertDatabaseHas('audit_logs', ['action' => 'NOTIFICATION_DISMISSED']);
        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(2, 'data');

        $this->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 1)->assertJsonPath('meta.unread_count', 0);
        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', 0);

        $this->deleteJson("/api/notifications/{$c->id}")->assertOk();
        $this->assertDatabaseMissing('notifications', ['id' => $c->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'NOTIFICATION_DELETED']);
        $this->deleteJson("/api/notifications/{$c->id}")->assertStatus(404);

        // Deleting a notification never deletes its audit history
        $this->assertGreaterThanOrEqual(4, \App\Models\AuditLog::where('entity_type', 'Notification')->count());
    }

    public function test_invalid_ids_return_404_not_500(): void
    {
        $this->login();
        $this->getJson('/api/notifications/not-a-uuid')->assertStatus(404);
        $this->postJson('/api/notifications/123/read')->assertStatus(404);
        $this->postJson('/api/notifications/' . \Illuminate\Support\Str::uuid() . '/dismiss')->assertStatus(404);
        $this->deleteJson('/api/notifications/' . \Illuminate\Support\Str::uuid())->assertStatus(404);
    }

    public function test_expired_notifications_are_hidden_but_purge_keeps_audit_rows(): void
    {
        $this->login();
        $service = app(\App\Services\Notification\NotificationService::class);
        $live = $service->notify($this->user, NotificationType::SYSTEM_ALERT, ['title' => 'Live', 'message' => 'm', 'dedupe_key' => 'live']);
        $expired = $service->notify($this->user, NotificationType::SYSTEM_ALERT, ['title' => 'Gone', 'message' => 'm', 'dedupe_key' => 'gone', 'expires_at' => now()->subDays(2)]);
        $this->assertNotNull($live);
        $this->assertNotNull($expired);
        $this->assertSame(2, \App\Models\AuditLog::where('action', 'NOTIFICATION_CREATED')->count());

        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Live');
        $this->getJson("/api/notifications/{$expired->id}")->assertOk(); // still addressable directly until purged

        $this->artisan('notifications:purge')->assertSuccessful();
        $this->assertDatabaseMissing('notifications', ['id' => $expired->id]);
        $this->assertDatabaseHas('notifications', ['id' => $live->id]);
        $this->assertSame(2, \App\Models\AuditLog::where('action', 'NOTIFICATION_CREATED')->count(), 'audit rows survive purge');
    }

    public function test_purge_respects_configured_retention_and_can_be_disabled(): void
    {
        $this->login();
        Notification::factory()->for($this->user)->read()->create(['created_at' => now()->subDays(100)]);
        Notification::factory()->for($this->user)->create(['created_at' => now()->subDays(400)]);
        Notification::factory()->for($this->user)->create(['created_at' => now()->subDays(100)]); // unread, within max

        config(['notifications.retention.read_retention_days' => 0, 'notifications.retention.max_retention_days' => 0]);
        $this->artisan('notifications:purge')->assertSuccessful();
        $this->assertSame(3, Notification::count());

        config(['notifications.retention.read_retention_days' => 90, 'notifications.retention.max_retention_days' => 365]);
        $this->artisan('notifications:purge')->assertSuccessful();
        $this->assertSame(1, Notification::count());
    }

    public function test_legacy_collaboration_rows_are_backfilled_by_migration_shape(): void
    {
        $this->login();
        // A STEP 34 row (type = channel class, payload in data) promoted by the migration's back-fill logic
        \Illuminate\Support\Facades\DB::table('notifications')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'App\\Notifications\\CollaborationNotification',
            'notifiable_type' => User::class, 'notifiable_id' => $this->user->id, 'user_id' => null,
            'data' => json_encode(['event' => 'COLLABORATION_INVITATION_ACCEPTED', 'title' => 'Accepted', 'body' => 'x', 'course_id' => 5, 'url' => 'http://localhost:3000/courses/5']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration = require base_path('database/migrations/2026_09_19_100001_extend_notifications_for_facultylens.php');
        (fn () => $this->backfillCollaborationRows())->call($migration);

        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'COLLABORATION_ACCEPTED')->assertJsonPath('data.0.category', 'COLLABORATION')
            ->assertJsonPath('data.0.title', 'Accepted')->assertJsonPath('data.0.entity_id', 5);
    }
}
