<?php

namespace App\Events;


/** STEP 47: an AI assessment analysis run reached the completed state. */
class AssessmentAnalysisCompleted extends DomainEvent
{
    public function __construct(
        public \App\Models\AnalysisReport $report,
    ) {}
}
