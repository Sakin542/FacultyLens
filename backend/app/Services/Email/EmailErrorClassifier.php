<?php

namespace App\Services\Email;

use Illuminate\View\ViewException;
use InvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Throwable;

/**
 * Maps transport / rendering failures to a stable error code and a retry decision.
 *
 *   temporary  → the job retries with backoff (connection refused, timeout, DNS, 4xx SMTP replies, rate limiting)
 *   permanent  → the delivery is marked FAILED immediately (bad recipient, authentication rejected, template error,
 *                5xx "mailbox unavailable" replies). An invalid address is never retried.
 */
final class EmailErrorClassifier
{
    public const CONNECTION_FAILED = 'SMTP_CONNECTION_FAILED';
    public const AUTHENTICATION_FAILED = 'SMTP_AUTHENTICATION_FAILED';
    public const TIMEOUT = 'SMTP_TIMEOUT';
    public const DNS_FAILED = 'DNS_RESOLUTION_FAILED';
    public const TEMPORARY_REJECTION = 'SMTP_TEMPORARY_REJECTION';
    public const RATE_LIMITED = 'SMTP_RATE_LIMITED';
    public const PERMANENT_REJECTION = 'SMTP_PERMANENT_REJECTION';
    public const INVALID_RECIPIENT = 'INVALID_RECIPIENT';
    public const TEMPLATE_ERROR = 'TEMPLATE_RENDER_FAILED';
    public const CONFIGURATION_ERROR = 'MAIL_CONFIGURATION_ERROR';
    public const QUEUE_ERROR = 'QUEUE_DISPATCH_FAILED';
    public const UNKNOWN = 'UNKNOWN_ERROR';

    /** @return array{code:string, permanent:bool, message:string} */
    public static function classify(Throwable $e): array
    {
        $message = EmailLogSanitizer::string($e->getMessage());
        $lower = strtolower($message);

        if ($e instanceof RfcComplianceException || str_contains($lower, 'does not comply with addr-spec')) {
            return self::result(self::INVALID_RECIPIENT, true, $message);
        }
        if ($e instanceof InvalidArgumentException && str_contains($lower, 'recipient')) {
            return self::result(self::INVALID_RECIPIENT, true, $message);
        }
        if ($e instanceof ViewException || ($e instanceof InvalidArgumentException && str_contains($lower, 'view'))
            || str_contains($lower, 'view [') || str_contains($lower, 'undefined variable')) {
            return self::result(self::TEMPLATE_ERROR, true, $message);
        }
        if (str_contains($lower, 'unsupported mail transport') || (str_contains($lower, 'mailer [') && str_contains($lower, 'not defined'))) {
            return self::result(self::CONFIGURATION_ERROR, true, $message);
        }

        $smtpCode = self::smtpCode($e, $message);

        if ($e instanceof UnexpectedResponseException || $smtpCode !== null) {
            if ($smtpCode === 535 || $smtpCode === 534 || str_contains($lower, 'authentication') || str_contains($lower, 'username and password not accepted')) {
                return self::result(self::AUTHENTICATION_FAILED, true, $message);
            }
            if ($smtpCode === 421 || $smtpCode === 450 || $smtpCode === 451 || $smtpCode === 452) {
                $rate = str_contains($lower, 'rate') || str_contains($lower, 'too many') || str_contains($lower, 'quota') || str_contains($lower, 'limit');

                return self::result($rate ? self::RATE_LIMITED : self::TEMPORARY_REJECTION, false, $message);
            }
            if ($smtpCode === 550 || $smtpCode === 551 || $smtpCode === 553 || $smtpCode === 501 || $smtpCode === 513) {
                return self::result(self::INVALID_RECIPIENT, true, $message);
            }
            if ($smtpCode !== null && $smtpCode >= 500) {
                return self::result(self::PERMANENT_REJECTION, true, $message);
            }
            if ($smtpCode !== null && $smtpCode >= 400) {
                return self::result(self::TEMPORARY_REJECTION, false, $message);
            }
        }

        if ($e instanceof TransportExceptionInterface || $e instanceof TransportException) {
            if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
                return self::result(self::TIMEOUT, false, $message);
            }
            if (str_contains($lower, 'getaddrinfo') || str_contains($lower, 'name or service not known') || str_contains($lower, 'could not resolve') || str_contains($lower, 'nodename nor servname')) {
                return self::result(self::DNS_FAILED, false, $message);
            }
            if (str_contains($lower, 'authentication') || str_contains($lower, 'credentials')) {
                return self::result(self::AUTHENTICATION_FAILED, true, $message);
            }

            return self::result(self::CONNECTION_FAILED, false, $message);
        }

        if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            return self::result(self::TIMEOUT, false, $message);
        }
        if (str_contains($lower, 'connection refused') || str_contains($lower, 'connection reset') || str_contains($lower, 'could not connect')) {
            return self::result(self::CONNECTION_FAILED, false, $message);
        }
        if (str_contains($lower, 'getaddrinfo') || str_contains($lower, 'could not resolve')) {
            return self::result(self::DNS_FAILED, false, $message);
        }

        return self::result(self::UNKNOWN, false, $message);
    }

    public static function isPermanent(Throwable $e): bool
    {
        return self::classify($e)['permanent'];
    }

    private static function smtpCode(Throwable $e, string $message): ?int
    {
        if ($e instanceof UnexpectedResponseException && $e->getCode() >= 400) {
            return (int) $e->getCode();
        }
        if (preg_match('/(?:code\s*"?|^|\s)([45]\d{2})(?:[\s"\-]|$)/', $message, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /** @return array{code:string, permanent:bool, message:string} */
    private static function result(string $code, bool $permanent, string $message): array
    {
        return ['code' => $code, 'permanent' => $permanent, 'message' => $message];
    }
}
