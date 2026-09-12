<?php

namespace App\Services\Explainability;

use Exception;

class ExplainabilityException extends Exception
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
