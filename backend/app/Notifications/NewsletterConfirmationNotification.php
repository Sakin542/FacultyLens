<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Double opt-in confirmation for the Faculty dispatch list. Carries only the confirm/unsubscribe links.
 */
class NewsletterConfirmationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected string $confirmUrl, protected string $unsubscribeUrl, protected int $ttlHours) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your Faculty dispatch subscription')
            ->greeting('Hello,')
            ->line('You asked to receive the FacultyLens Faculty dispatch — occasional notes on assessment design and AI governance.')
            ->line('Please confirm that this address belongs to you. Nothing will be sent until you do.')
            ->action('Confirm subscription', $this->confirmUrl)
            ->line("This link expires in {$this->ttlHours} hours. If you did not request this, you can ignore this e-mail or remove the address here: {$this->unsubscribeUrl}");
    }
}
