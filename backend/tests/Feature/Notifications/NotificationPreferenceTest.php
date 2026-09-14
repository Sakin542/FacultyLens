<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 47: notification preferences API — matrix, bulk update, single patch, mandatory categories.
 */
class NotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_matrix_lists_every_registered_type_with_defaults(): void
    {
        $res = $this->getJson('/api/notification-preferences')->assertOk();
        $prefs = collect($res->json('data.preferences'));

        $this->assertSame(count(NotificationType::all()), $prefs->count());
        $this->assertTrue($prefs->every(fn ($p) => $p['in_app_enabled'] === true && $p['email_enabled'] === false));
        $security = $prefs->firstWhere('notification_type', 'SECURITY_ALERT');
        $this->assertTrue($security['mandatory']);
        $this->assertFalse($prefs->firstWhere('notification_type', 'AI_ANALYSIS_COMPLETED')['mandatory']);
        $res->assertJsonPath('data.mandatory_categories', ['SECURITY', 'SYSTEM']);
        $this->assertFalse($res->json('data.email_available'), 'array mailer in tests → email not available');
        foreach ($prefs as $p) {
            $this->assertArrayHasKey('category', $p);
            $this->assertArrayHasKey('label', $p);
        }
    }

    public function test_bulk_update_persists_and_validates(): void
    {
        $this->putJson('/api/notification-preferences', ['preferences' => [
            ['notification_type' => 'AI_ANALYSIS_COMPLETED', 'in_app_enabled' => false],
            ['notification_type' => 'REPORT_GENERATED', 'in_app_enabled' => false, 'email_enabled' => true],
            ['notification_type' => 'SECURITY_ALERT', 'in_app_enabled' => false],
        ]])->assertOk();

        $this->assertDatabaseHas('notification_preferences', ['user_id' => $this->user->id, 'notification_type' => 'AI_ANALYSIS_COMPLETED', 'in_app_enabled' => false]);
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $this->user->id, 'notification_type' => 'REPORT_GENERATED', 'in_app_enabled' => false, 'email_enabled' => true]);
        // mandatory: request to disable is ignored
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $this->user->id, 'notification_type' => 'SECURITY_ALERT', 'in_app_enabled' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'NOTIFICATION_PREFERENCE_UPDATED', 'user_id' => $this->user->id]);

        $prefs = collect($this->getJson('/api/notification-preferences')->json('data.preferences'));
        $this->assertFalse($prefs->firstWhere('notification_type', 'AI_ANALYSIS_COMPLETED')['in_app_enabled']);
        $this->assertTrue($prefs->firstWhere('notification_type', 'SECURITY_ALERT')['in_app_enabled']);

        $this->putJson('/api/notification-preferences', ['preferences' => [['notification_type' => 'BOGUS', 'in_app_enabled' => false]]])->assertStatus(422);
        $this->putJson('/api/notification-preferences', ['preferences' => []])->assertStatus(422);
        $this->putJson('/api/notification-preferences', ['preferences' => [['notification_type' => 'AI_ANALYSIS_COMPLETED']]])->assertStatus(422);
    }

    public function test_patch_single_type(): void
    {
        $this->patchJson('/api/notification-preferences/report_generated', ['in_app_enabled' => false])->assertOk()
            ->assertJsonPath('data.notification_type', 'REPORT_GENERATED')->assertJsonPath('data.in_app_enabled', false)->assertJsonPath('data.mandatory', false);
        $this->patchJson('/api/notification-preferences/REPORT_GENERATED', ['in_app_enabled' => true])->assertOk()->assertJsonPath('data.in_app_enabled', true);
        $this->patchJson('/api/notification-preferences/SYSTEM_ALERT', ['in_app_enabled' => false])->assertOk()->assertJsonPath('data.in_app_enabled', true)->assertJsonPath('data.mandatory', true);
        $this->patchJson('/api/notification-preferences/UNKNOWN', ['in_app_enabled' => false])->assertStatus(404);
        $this->patchJson('/api/notification-preferences/REPORT_GENERATED', [])->assertStatus(422);
    }

    public function test_disabled_preference_stops_delivery_of_that_type_only(): void
    {
        $this->patchJson('/api/notification-preferences/AI_ANALYSIS_COMPLETED', ['in_app_enabled' => false])->assertOk();

        $service = app(\App\Services\Notification\NotificationService::class);
        $service->notify($this->user, NotificationType::AI_ANALYSIS_COMPLETED, ['title' => 'x', 'message' => 'y', 'dedupe_key' => 'a']);
        $service->notify($this->user, NotificationType::AI_RECOMMENDATION_CREATED, ['title' => 'x', 'message' => 'y', 'dedupe_key' => 'b']);

        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'AI_RECOMMENDATION_CREATED');
    }
}
