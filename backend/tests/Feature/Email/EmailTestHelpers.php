<?php

namespace Tests\Feature\Email;

use App\Models\EmailDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\NotificationType;
use App\Services\Notification\NotificationService;

/**
 * Shared fixtures for the e-mail suites. The queue is `sync` and the mailer `array` in phpunit.xml, so
 * notify() → EmailService → SendFacultyLensEmailJob → Mail runs inline and can be asserted with Mail::fake().
 */
trait EmailTestHelpers
{
    protected function faculty(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    protected function admin(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->forceFill(['role' => 'ADMIN'])->save();

        return $user->fresh();
    }

    protected function enableEmail(User $user, string $type, bool $enabled = true): void
    {
        NotificationPreference::updateOrCreate(['user_id' => $user->id, 'notification_type' => $type], ['in_app_enabled' => true, 'email_enabled' => $enabled]);
    }

    protected function notify(User $user, string $type = NotificationType::REPORT_GENERATED, array $overrides = [], array $options = [])
    {
        return app(NotificationService::class)->notify($user, $type, array_replace([
            'title' => 'Report ready',
            'message' => 'Your requested report has been generated successfully.',
            'action_url' => '/reports/42',
            'entity_type' => 'institutional_report',
            'entity_id' => 42,
            'data' => ['report_id' => 42, 'report_type' => 'COURSE_OUTCOME_ATTAINMENT', 'format' => 'pdf', 'action_label' => 'Open Report'],
        ], $overrides), $options);
    }

    protected function lastDelivery(): ?EmailDelivery
    {
        return EmailDelivery::query()->orderByDesc('id')->first();
    }
}
