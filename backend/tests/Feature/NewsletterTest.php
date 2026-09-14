<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\NewsletterSubscriber;
use App\Notifications\NewsletterConfirmationNotification;
use App\Notifications\NewsletterDispatchNotification;
use App\Services\NewsletterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NewsletterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['newsletter.frontend_url' => 'http://frontend.test']);
    }

    /** Pulls the raw confirmation token out of the link that was e-mailed. */
    protected function confirmTokenFor(string $email): string
    {
        $token = null;
        Notification::assertSentTo(
            NewsletterSubscriber::where('email', $email)->firstOrFail(),
            NewsletterConfirmationNotification::class,
            function (NewsletterConfirmationNotification $n) use (&$token) {
                $mail = $n->toMail(new AnonymousNotifiable);
                $token = basename($mail->actionUrl);

                return true;
            },
        );

        return $token;
    }

    public function test_subscribe_creates_pending_row_and_sends_confirmation_only(): void
    {
        $res = $this->postJson('/api/newsletter/subscribe', ['email' => 'Ada@University.EDU']);

        $res->assertStatus(202)->assertJsonPath('status', 'success')->assertJsonPath('message', 'Check your inbox — we sent a link to confirm your subscription.');
        $row = NewsletterSubscriber::where('email', 'ada@university.edu')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->confirmed_at);
        $this->assertNotNull($row->confirmation_token_hash);
        $this->assertSame(64, strlen($row->unsubscribe_token));
        $this->assertStringNotContainsString('confirmation_token_hash', $res->getContent());

        Notification::assertSentTo($row, NewsletterConfirmationNotification::class, function (NewsletterConfirmationNotification $n) use ($row) {
            $mail = $n->toMail($row);
            $this->assertStringStartsWith('http://frontend.test/newsletter/confirm/', $mail->actionUrl);
            $this->assertStringNotContainsString($row->confirmation_token_hash, $mail->actionUrl, 'link must carry the raw token, not the hash');

            return true;
        });
        Notification::assertNotSentTo($row, NewsletterDispatchNotification::class);
        $this->assertNotNull(AuditLog::where('action', 'NEWSLETTER_CONFIRMATION_SENT')->first());
    }

    public function test_invalid_email_is_rejected_with_friendly_message(): void
    {
        $this->postJson('/api/newsletter/subscribe', ['email' => 'not-an-email'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'Enter a valid email address.');
        $this->postJson('/api/newsletter/subscribe', [])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'Enter your email address.');
        $this->assertSame(0, NewsletterSubscriber::count());
    }

    public function test_confirm_activates_subscription_and_link_is_single_use(): void
    {
        $this->postJson('/api/newsletter/subscribe', ['email' => 'ada@university.edu'])->assertStatus(202);
        $token = $this->confirmTokenFor('ada@university.edu');

        $this->postJson("/api/newsletter/confirm/{$token}")->assertOk()->assertJsonPath('status', 'success');

        $row = NewsletterSubscriber::where('email', 'ada@university.edu')->first();
        $this->assertTrue($row->isConfirmed());
        $this->assertNull($row->confirmation_token_hash);
        $this->assertNotNull(AuditLog::where('action', 'NEWSLETTER_CONFIRMED')->first());

        $this->postJson("/api/newsletter/confirm/{$token}")->assertStatus(404);
    }

    public function test_confirm_rejects_unknown_malformed_and_expired_tokens(): void
    {
        $this->postJson('/api/newsletter/confirm/' . str_repeat('a', 64))->assertStatus(404);
        $this->postJson('/api/newsletter/confirm/short')->assertStatus(404);
        $this->postJson('/api/newsletter/confirm/' . str_repeat('%27', 30))->assertStatus(404);

        $this->postJson('/api/newsletter/subscribe', ['email' => 'late@university.edu'])->assertStatus(202);
        $token = $this->confirmTokenFor('late@university.edu');
        NewsletterSubscriber::where('email', 'late@university.edu')->update(['confirmation_sent_at' => now()->subHours(49)]);

        $this->postJson("/api/newsletter/confirm/{$token}")->assertStatus(410);
        $this->assertNull(NewsletterSubscriber::where('email', 'late@university.edu')->first()->confirmed_at);
    }

    public function test_subscribe_response_is_identical_for_new_pending_and_confirmed_addresses(): void
    {
        $first = $this->postJson('/api/newsletter/subscribe', ['email' => 'same@university.edu']);
        $pending = $this->postJson('/api/newsletter/subscribe', ['email' => 'same@university.edu']);
        $this->postJson('/api/newsletter/confirm/' . $this->confirmTokenFor('same@university.edu'))->assertOk();
        $confirmed = $this->postJson('/api/newsletter/subscribe', ['email' => 'same@university.edu']);

        $this->assertSame($first->getContent(), $pending->getContent());
        $this->assertSame($first->getContent(), $confirmed->getContent());
        $this->assertSame(1, NewsletterSubscriber::count());
        // Cooldown: the second request within 10 minutes did not send another e-mail; confirmed address never does.
        Notification::assertSentTimes(NewsletterConfirmationNotification::class, 1);
    }

    public function test_resend_after_cooldown_rotates_the_confirmation_token(): void
    {
        $this->postJson('/api/newsletter/subscribe', ['email' => 'slow@university.edu'])->assertStatus(202);
        $firstHash = NewsletterSubscriber::first()->confirmation_token_hash;
        $this->travel(11)->minutes();

        $this->postJson('/api/newsletter/subscribe', ['email' => 'slow@university.edu'])->assertStatus(202);

        Notification::assertSentTimes(NewsletterConfirmationNotification::class, 2);
        $this->assertNotSame($firstHash, NewsletterSubscriber::first()->confirmation_token_hash);
        $this->assertSame(1, NewsletterSubscriber::count());
    }

    public function test_unsubscribe_is_idempotent_and_excludes_from_dispatch(): void
    {
        $this->postJson('/api/newsletter/subscribe', ['email' => 'out@university.edu'])->assertStatus(202);
        $this->postJson('/api/newsletter/confirm/' . $this->confirmTokenFor('out@university.edu'))->assertOk();
        $row = NewsletterSubscriber::first();

        $this->postJson("/api/newsletter/unsubscribe/{$row->unsubscribe_token}")->assertOk()->assertJsonPath('status', 'success');
        $this->postJson("/api/newsletter/unsubscribe/{$row->unsubscribe_token}")->assertOk();
        $this->postJson('/api/newsletter/unsubscribe/' . str_repeat('b', 64))->assertStatus(404);

        $this->assertNotNull($row->fresh()->unsubscribed_at);
        $this->assertFalse($row->fresh()->isConfirmed());
        $this->assertSame(1, AuditLog::where('action', 'NEWSLETTER_UNSUBSCRIBED')->count());
        $this->assertSame(0, app(NewsletterService::class)->sendDispatch('Issue 1', 'Body'));
    }

    public function test_resubscribe_after_unsubscribe_requires_a_fresh_confirmation(): void
    {
        $this->postJson('/api/newsletter/subscribe', ['email' => 'back@university.edu'])->assertStatus(202);
        $this->postJson('/api/newsletter/confirm/' . $this->confirmTokenFor('back@university.edu'))->assertOk();
        $row = NewsletterSubscriber::first();
        $this->postJson("/api/newsletter/unsubscribe/{$row->unsubscribe_token}")->assertOk();
        $this->travel(11)->minutes();

        $this->postJson('/api/newsletter/subscribe', ['email' => 'back@university.edu'])->assertStatus(202);
        $this->assertFalse($row->fresh()->isConfirmed(), 'must stay inactive until re-confirmed');
        Notification::assertSentTimes(NewsletterConfirmationNotification::class, 2);

        $this->postJson('/api/newsletter/confirm/' . $this->confirmTokenFor('back@university.edu'))->assertOk();
        $this->assertTrue($row->fresh()->isConfirmed());
        $this->assertNull($row->fresh()->unsubscribed_at);
    }

    public function test_dispatch_goes_only_to_confirmed_subscribers_with_their_unsubscribe_link(): void
    {
        foreach (['a', 'b', 'c'] as $n) {
            $this->postJson('/api/newsletter/subscribe', ['email' => "{$n}@university.edu"])->assertStatus(202);
        }
        $this->postJson('/api/newsletter/confirm/' . $this->confirmTokenFor('a@university.edu'))->assertOk();
        $this->postJson('/api/newsletter/confirm/' . $this->confirmTokenFor('b@university.edu'))->assertOk();
        $b = NewsletterSubscriber::where('email', 'b@university.edu')->first();
        $this->postJson("/api/newsletter/unsubscribe/{$b->unsubscribe_token}")->assertOk();

        $sent = app(NewsletterService::class)->sendDispatch('Dispatch #1', "First paragraph.\n\nSecond paragraph.", 'Read more', 'https://facultylens.test/notes/1');

        $this->assertSame(1, $sent);
        $a = NewsletterSubscriber::where('email', 'a@university.edu')->first();
        Notification::assertSentTo($a, NewsletterDispatchNotification::class, function (NewsletterDispatchNotification $n) use ($a) {
            $mail = $n->toMail($a);
            $this->assertSame('Dispatch #1', $mail->subject);
            $this->assertSame('Read more', $mail->actionText);
            $this->assertStringContainsString("/newsletter/unsubscribe/{$a->unsubscribe_token}", implode("\n", array_map('strval', $mail->outroLines)));

            return true;
        });
        Notification::assertNotSentTo($b, NewsletterDispatchNotification::class);
        Notification::assertNotSentTo(NewsletterSubscriber::where('email', 'c@university.edu')->first(), NewsletterDispatchNotification::class);
        $this->assertSame(1, AuditLog::where('action', 'NEWSLETTER_DISPATCH_SENT')->first()->metadata['recipients']);
    }

    public function test_send_command_dry_run_and_purge_command(): void
    {
        $this->postJson('/api/newsletter/subscribe', ['email' => 'stale@university.edu'])->assertStatus(202);
        $this->postJson('/api/newsletter/subscribe', ['email' => 'live@university.edu'])->assertStatus(202);
        $this->postJson('/api/newsletter/confirm/' . $this->confirmTokenFor('live@university.edu'))->assertOk();

        $this->artisan('newsletter:send', ['subject' => 'Hello', '--body' => 'Body', '--dry-run' => true])
            ->expectsOutputToContain('1 confirmed subscriber(s)')->assertExitCode(0);
        $this->artisan('newsletter:send', ['subject' => 'Hello'])->assertExitCode(1);
        Notification::assertNotSentTo(NewsletterSubscriber::where('email', 'live@university.edu')->first(), NewsletterDispatchNotification::class);

        NewsletterSubscriber::where('email', 'stale@university.edu')->update(['created_at' => now()->subDays(8), 'confirmation_sent_at' => now()->subDays(8)]);
        $this->artisan('newsletter:purge-unconfirmed')->expectsOutputToContain('Purged 1')->assertExitCode(0);
        $this->assertSame(1, NewsletterSubscriber::count());
        $this->assertSame('live@university.edu', NewsletterSubscriber::first()->email);
    }

    public function test_subscribe_is_rate_limited_per_ip(): void
    {
        config(['newsletter.subscribe_rate_limit_per_hour' => 2]);

        $this->postJson('/api/newsletter/subscribe', ['email' => 'r1@university.edu'])->assertStatus(202);
        $this->postJson('/api/newsletter/subscribe', ['email' => 'r2@university.edu'])->assertStatus(202);
        $this->postJson('/api/newsletter/subscribe', ['email' => 'r3@university.edu'])
            ->assertStatus(429)->assertJsonPath('message', 'Too many subscription attempts. Please try again later.');
        $this->assertSame(2, NewsletterSubscriber::count());
    }

    public function test_audit_metadata_never_contains_the_email_address(): void
    {
        $this->postJson('/api/newsletter/subscribe', ['email' => 'secret@university.edu'])->assertStatus(202);
        $this->postJson('/api/newsletter/confirm/' . $this->confirmTokenFor('secret@university.edu'))->assertOk();

        foreach (AuditLog::where('action', 'like', 'NEWSLETTER_%')->get() as $log) {
            $this->assertStringNotContainsString('secret@university.edu', json_encode($log->metadata));
        }
    }
}
