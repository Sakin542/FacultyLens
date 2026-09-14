<?php

namespace App\Events;


/** STEP 47: an institutional report finished generating and its file is stored. */
class ReportGenerated extends DomainEvent
{
    public function __construct(
        public \App\Models\InstitutionalReport $report,
    ) {}
}
