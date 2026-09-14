<?php

namespace App\Console\Commands;

use App\Services\Email\EmailLogSanitizer;
use Illuminate\Console\Command;

/**
 * Prints the effective mail/queue configuration. MAIL_PASSWORD is always masked — this command exists precisely so
 * nobody has to `cat .env` or `config('mail')` in tinker to verify the setup.
 */
class EmailCheckCommand extends Command
{
    protected $signature = 'email:check';

    protected $description = 'Show the effective FacultyLens e-mail configuration (secrets masked)';

    public function handle(): int
    {
        self::printConfig($this);

        $mailer = (string) config('mail.default');
        $problems = [];
        if ($mailer === 'smtp') {
            $smtp = (array) config('mail.mailers.smtp');
            if (empty($smtp['host'])) {
                $problems[] = 'MAIL_HOST is empty.';
            }
            if (empty($smtp['username'])) {
                $problems[] = 'MAIL_USERNAME is empty.';
            }
            if (empty($smtp['password'])) {
                $problems[] = 'MAIL_PASSWORD is empty — set the Gmail App Password in backend/.env (never commit it).';
            }
        }
        if (in_array((string) config('mail.from.address'), ['', 'hello@example.com'], true)) {
            $problems[] = 'MAIL_FROM_ADDRESS is not set.';
        }
        if (!config('email.enabled')) {
            $problems[] = 'EMAIL_ENABLED=false — nothing will be queued.';
        }

        $this->newLine();
        if ($problems === []) {
            $this->info('Mail configuration looks complete.');

            return self::SUCCESS;
        }
        foreach ($problems as $p) {
            $this->warn('• ' . $p);
        }

        return self::FAILURE;
    }

    public static function printConfig(Command $cmd): void
    {
        $mailer = (string) config('mail.default');
        $smtp = (array) config("mail.mailers.{$mailer}", []);
        $rows = [
            ['MAIL_MAILER', $mailer],
            ['MAIL_HOST', (string) ($smtp['host'] ?? '-')],
            ['MAIL_PORT', (string) ($smtp['port'] ?? '-')],
            ['MAIL_ENCRYPTION', (string) ($smtp['encryption'] ?? ($smtp['scheme'] ?? '-'))],
            ['MAIL_USERNAME', (string) ($smtp['username'] ?? '-')],
            ['MAIL_PASSWORD', !empty($smtp['password']) ? EmailLogSanitizer::MASK : '(empty)'],
            ['MAIL_FROM_ADDRESS', (string) config('mail.from.address')],
            ['MAIL_FROM_NAME', (string) config('mail.from.name')],
            ['EMAIL_ENABLED', config('email.enabled') ? 'true' : 'false'],
            ['QUEUE_CONNECTION', (string) (config('email.queue.connection') ?: config('queue.default'))],
            ['EMAIL_QUEUE', (string) config('email.queue.name')],
            ['FRONTEND_URL', (string) config('email.frontend_url')],
        ];
        $cmd->table(['Setting', 'Value'], $rows);
    }
}
