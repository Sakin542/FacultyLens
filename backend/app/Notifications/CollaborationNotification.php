<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * STEP 34 / STEP 47: e-mail carrier for collaboration invitations (the only collaboration message that must reach
 * people without an account). In-app rows are created by App\Services\Notification\NotificationService, never by
 * this class, so the two systems cannot double-write. Payload carries only non-sensitive metadata.
 */
class CollaborationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param array<string, mixed> $data  keys: event, title, body, course_id, course_code, course_name, actor_name, role, url, action_text, expires_at
     */
    public function __construct(protected array $data, protected bool $sendMail = true) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->data;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->data['title'] ?? 'FacultyLens Collaboration')
            ->greeting('Hello ' . ($notifiable->name ?? 'there') . ',')
            ->line($this->data['body'] ?? '');

        if (!empty($this->data['course_name'])) {
            $mail->line('Course: ' . trim(($this->data['course_code'] ?? '') . ' ' . $this->data['course_name']));
        }
        if (!empty($this->data['role'])) {
            $mail->line('Role: ' . ucfirst(strtolower($this->data['role'])));
        }
        if (!empty($this->data['expires_at'])) {
            $mail->line('This invitation expires on ' . $this->data['expires_at'] . '.');
        }
        if (!empty($this->data['url'])) {
            $mail->action($this->data['action_text'] ?? 'Open FacultyLens', $this->data['url']);
        }

        return $mail->line('AI-generated findings in FacultyLens remain assistive; faculty decisions are always made by faculty.');
    }
}
