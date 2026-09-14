<?php

namespace App\Events;


/** STEP 47: an AI assessment analysis run failed (no academic data changed). */
class AssessmentAnalysisFailed extends DomainEvent
{
    public function __construct(
        public \App\Models\AnalysisReport $report,
    ) {}
}
