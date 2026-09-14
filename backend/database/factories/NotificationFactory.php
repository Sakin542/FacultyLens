<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\User;
use App\Notifications\NotificationType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * STEP 47: test fixture only — production notifications are always created by NotificationService.
 *
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        $type = NotificationType::AI_ANALYSIS_COMPLETED;

        return [
            'id' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'notifiable_type' => User::class,
            'notifiable_id' => fn (array $attrs) => $attrs['user_id'],
            'type' => $type,
            'category' => NotificationType::category($type),
            'severity' => NotificationType::defaultSeverity($type),
            'title' => 'Assessment analysis completed',
            'message' => 'AI analysis for Midterm Examination is ready for review.',
            'data' => ['assessment_id' => 1, 'analysis_id' => 1],
            'action_url' => '/assessments/1/analysis',
            'entity_type' => 'analysis_report',
            'entity_id' => 1,
            'dedupe_key' => 'factory:' . Str::uuid(),
            'read_at' => null,
            'dismissed_at' => null,
            'expires_at' => null,
        ];
    }

    public function ofType(string $type, array $overrides = []): static
    {
        return $this->state(fn () => [
            'type' => $type,
            'category' => NotificationType::category($type),
            'severity' => NotificationType::defaultSeverity($type),
            'title' => NotificationType::label($type),
        ] + $overrides);
    }

    public function read(): static
    {
        return $this->state(fn () => ['read_at' => now()->subMinute()]);
    }

    public function dismissed(): static
    {
        return $this->state(fn () => ['dismissed_at' => now()->subMinute(), 'read_at' => now()->subMinute()]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }
}
