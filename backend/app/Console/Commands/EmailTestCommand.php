<?php

namespace App\Console\Commands;

use App\Models\EmailDelivery;
use App\Models\User;
use App\Services\Email\EmailLogSanitizer;
use App\Services\Email\EmailService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Operator diagnostics for the e-mail pipeline.
 *
 *   php artisan email:check                    prints the effective mail configuration with the password masked
 *   php artisan email:test user@example.com    queues one test message (add --sync to send inline and wait)
 */
class EmailTestCommand extends Command
{
    protected $signature = 'email:test {recipient : destination address} {--sync : send inline instead of queueing} {--as= : e-mail of the account to attribute the test to (default: first ADMIN)}';

    protected $description = 'Queue (or send) one FacultyLens test e-mail through the configured mailer';

    public function handle(EmailService $emails): int
    {
        $recipient = (string) $this->argument('recipient');
        $as = $this->option('as');
        $user = $as
            ? User::query()->where('email', strtolower(trim((string) $as)))->first()
            : (User::query()->where('role', 'ADMIN')->orderBy('id')->first() ?? User::query()->orderBy('id')->first());
        if (!$user) {
            $this->error('No user account found to attribute the test to.');

            return self::FAILURE;
        }

        EmailCheckCommand::printConfig($this);

        try {
            $delivery = $emails->sendTestEmail($user, $recipient, (bool) $this->option('sync'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf('Delivery #%d → %s  status=%s  attempts=%d', $delivery->id, EmailDelivery::maskAddress($delivery->recipient), $delivery->status, $delivery->attempts));
        if ($delivery->error_code) {
            $this->warn('Error: ' . $delivery->error_code . ' — ' . EmailLogSanitizer::string((string) $delivery->error_message));
        }
        if ($delivery->status === EmailDelivery::PENDING) {
            $this->line('Queued on connection "' . (config('email.queue.connection') ?: config('queue.default')) . '", queue "' . config('email.queue.name') . '". Watch it with: php artisan horizon (or queue:work) and GET /api/email/deliveries/' . $delivery->id);
        }

        return $delivery->status === EmailDelivery::FAILED ? self::FAILURE : self::SUCCESS;
    }
}
