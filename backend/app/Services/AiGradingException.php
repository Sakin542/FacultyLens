<?php

namespace App\Services;

use Exception;

/**
 * Domain exception for AI grading assistance with a safe HTTP status.
 * Messages must never contain student answer content.
 */
class AiGradingException extends Exception
{
    public function __construct(string $message, protected int $status = 422, protected array $details = [])
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
