<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A single Faculty dispatch issue. Body is plain text: each blank-line-separated paragraph becomes a line.
 */
class NewsletterDispatchNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $subject,
        protected string $body,
        protected string $unsubscribeUrl,
        protected ?string $actionText = null,
        protected ?string $actionUrl = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->greeting('Hello,');

        foreach (preg_split("/\R{2,}/", trim($this->body)) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph !== '') {
                $mail->line($paragraph);
            }
        }
        if ($this->actionUrl) {
            $mail->action($this->actionText ?: 'Open FacultyLens', $this->actionUrl);
        }

        return $mail
            ->line('You receive this because you confirmed a subscription to the FacultyLens Faculty dispatch.')
            ->line("Unsubscribe: {$this->unsubscribeUrl}");
    }
}
