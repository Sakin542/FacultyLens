<?php

namespace App\Services;

use App\Models\NewsletterSubscriber;
use App\Notifications\NewsletterConfirmationNotification;
use App\Notifications\NewsletterDispatchNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * "Faculty dispatch" newsletter: double opt-in subscribe → confirm → dispatch → unsubscribe.
 * Responses to the public subscribe endpoint are identical for new, pending and existing addresses
 * so the list cannot be used to probe who is subscribed.
 */
class NewsletterService
{
    public function __construct(protected AuditLogService $audit) {}

    /**
     * @return 'sent'|'cooldown'|'already_confirmed'
     */
    public function subscribe(string $email, string $source = 'footer'): string
    {
        $email = Str::lower(trim($email));

        return DB::transaction(function () use ($email, $source) {
            $subscriber = NewsletterSubscriber::where('email', $email)->lockForUpdate()->first();

            if ($subscriber && $subscriber->isConfirmed()) {
                return 'already_confirmed';
            }

            $cooldown = max(0, (int) config('newsletter.resend_cooldown_minutes', 10));
            if ($subscriber && $subscriber->confirmation_sent_at && $subscriber->confirmation_sent_at->gt(now()->subMinutes($cooldown))) {
                return 'cooldown';
            }

            $rawToken = Str::random(64);
            if (!$subscriber) {
                $subscriber = new NewsletterSubscriber([
                    'email' => $email,
                    'unsubscribe_token' => Str::random(64),
                    'source' => Str::limit($source, 32, ''),
                ]);
            }
            $subscriber->fill([
                'confirmation_token_hash' => NewsletterSubscriber::hashToken($rawToken),
                'confirmation_sent_at' => now(),
            ])->save();

            $subscriber->notify(new NewsletterConfirmationNotification(
                $this->frontendUrl("/newsletter/confirm/{$rawToken}"),
                $this->frontendUrl("/newsletter/unsubscribe/{$subscriber->unsubscribe_token}"),
                max(1, (int) config('newsletter.confirmation_ttl_hours', 48)),
            ));

            $this->audit->log('NEWSLETTER_CONFIRMATION_SENT', 'NewsletterSubscriber', $subscriber->id, ['source' => $subscriber->source, 'resend' => $subscriber->wasRecentlyCreated === false]);

            return 'sent';
        });
    }

    /**
     * @throws HttpException 404 unknown/used token, 410 expired token
     */
    public function confirm(string $token): NewsletterSubscriber
    {
        $subscriber = NewsletterSubscriber::where('confirmation_token_hash', NewsletterSubscriber::hashToken($token))->first();
        if (!$subscriber) {
            throw new HttpException(404, 'This confirmation link is not valid or has already been used.');
        }
        $ttl = max(1, (int) config('newsletter.confirmation_ttl_hours', 48));
        if (!$subscriber->confirmation_sent_at || $subscriber->confirmation_sent_at->lt(now()->subHours($ttl))) {
            throw new HttpException(410, 'This confirmation link has expired. Please subscribe again to receive a new one.');
        }

        $subscriber->forceFill([
            'confirmed_at' => now(),
            'unsubscribed_at' => null,
            'confirmation_token_hash' => null,
        ])->save();

        $this->audit->log('NEWSLETTER_CONFIRMED', 'NewsletterSubscriber', $subscriber->id, ['source' => $subscriber->source]);

        return $subscriber;
    }

    /**
     * Idempotent. @throws HttpException 404 unknown token
     */
    public function unsubscribe(string $token): NewsletterSubscriber
    {
        $subscriber = NewsletterSubscriber::where('unsubscribe_token', $token)->first();
        if (!$subscriber) {
            throw new HttpException(404, 'This unsubscribe link is not valid.');
        }
        if ($subscriber->unsubscribed_at === null) {
            $subscriber->forceFill(['unsubscribed_at' => now(), 'confirmation_token_hash' => null])->save();
            $this->audit->log('NEWSLETTER_UNSUBSCRIBED', 'NewsletterSubscriber', $subscriber->id, ['was_confirmed' => $subscriber->confirmed_at !== null]);
        }

        return $subscriber;
    }

    /** Queues one e-mail per active subscriber; returns the recipient count. */
    public function sendDispatch(string $subject, string $body, ?string $actionText = null, ?string $actionUrl = null): int
    {
        $count = 0;
        NewsletterSubscriber::active()->orderBy('id')->chunkById(200, function ($subscribers) use (&$count, $subject, $body, $actionText, $actionUrl) {
            foreach ($subscribers as $subscriber) {
                $subscriber->notify(new NewsletterDispatchNotification(
                    $subject,
                    $body,
                    $this->frontendUrl("/newsletter/unsubscribe/{$subscriber->unsubscribe_token}"),
                    $actionText,
                    $actionUrl,
                ));
                $count++;
            }
        });

        $this->audit->log('NEWSLETTER_DISPATCH_SENT', 'NewsletterSubscriber', null, ['subject' => Str::limit($subject, 120), 'recipients' => $count]);

        return $count;
    }

    /** Removes never-confirmed rows whose confirmation window has long passed. */
    public function purgeUnconfirmed(): int
    {
        $days = max(1, (int) config('newsletter.purge_unconfirmed_after_days', 7));

        return NewsletterSubscriber::whereNull('confirmed_at')
            ->where('created_at', '<', now()->subDays($days))
            ->where(fn ($q) => $q->whereNull('confirmation_sent_at')->orWhere('confirmation_sent_at', '<', now()->subDays($days)))
            ->delete();
    }

    public function activeCount(): int
    {
        return NewsletterSubscriber::active()->count();
    }

    protected function frontendUrl(string $path): string
    {
        return rtrim((string) config('newsletter.frontend_url'), '/') . $path;
    }
}
