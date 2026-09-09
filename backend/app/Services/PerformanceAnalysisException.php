<?php

namespace App\Services;

use Exception;

/** Domain exception for STEP 30 performance analysis with a safe HTTP status. */
class PerformanceAnalysisException extends Exception
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
