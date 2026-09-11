<?php

namespace App\Services\Reports;

use RuntimeException;

/** Safe, user-facing report validation failure (422). Never carries SQL, paths or stack detail. */
class ReportValidationException extends RuntimeException
{
    public function __construct(string $message, public readonly array $errors = [], public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
