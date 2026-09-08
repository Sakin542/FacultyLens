<?php

namespace App\Services;

use Exception;

/**
 * Domain exception for rubric operations carrying a safe HTTP status and user-facing message.
 */
class RubricException extends Exception
{
    public function __construct(string $message, protected int $status = 422)
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}
