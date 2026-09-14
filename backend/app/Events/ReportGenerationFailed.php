<?php

namespace App\Events;


/** STEP 47: an institutional report could not be generated. */
class ReportGenerationFailed extends DomainEvent
{
    public function __construct(
        public \App\Models\InstitutionalReport $report,
    ) {}
}
