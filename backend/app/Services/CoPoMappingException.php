<?php

namespace App\Services;

use Exception;

/** Domain exception for STEP 31 CO/PO mapping with a safe HTTP status. */
class CoPoMappingException extends Exception
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
