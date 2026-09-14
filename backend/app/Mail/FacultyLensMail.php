<?php

namespace App\Mail;

use App\Services\Email\EmailContentResolver;
use App\Services\Email\EmailPlainText;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * The single FacultyLens Mailable. Every outbound message (notification e-mails, password reset, test e-mail) is an
 * instance with a template name under resources/views/emails and a sanitised context. The plain-text alternative is
 * derived from the rendered HTML so no template can ship without one.
 *
 * Sent by SendFacultyLensEmailJob — not queued itself, so delivery state stays in email_deliveries.
 */
class FacultyLensMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $template,
        public string $subjectLine,
        public array $context = [],
        public ?int $deliveryId = null,
        public ?string $emailType = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        $view = 'emails.' . $this->template;
        $data = array_replace(app(EmailContentResolver::class)->baseContext(), $this->context, ['subject' => $this->subjectLine]);

        $html = view($view, $data)->render();

        return new Content(
            htmlString: $html,
            text: 'emails.text.plain',
            with: ['plain_text' => EmailPlainText::fromHtml($html)],
        );
    }

    public function headers(): Headers
    {
        $text = ['X-FacultyLens-Type' => $this->emailType ?? strtoupper(str_replace('-', '_', $this->template))];
        if ($this->deliveryId) {
            $text['X-FacultyLens-Delivery'] = (string) $this->deliveryId;
        }

        return new Headers(text: $text);
    }

    /** Rendered HTML (used by the preview endpoint and tests). */
    public function renderHtml(): string
    {
        return $this->render();
    }

    public function renderText(): string
    {
        return EmailPlainText::fromHtml($this->render());
    }
}
