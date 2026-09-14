<?php

namespace App\Events;


/** STEP 47: a student performance snapshot failed. */
class PerformanceAnalysisFailed extends DomainEvent
{
    public function __construct(
        public \App\Models\PerformanceAnalysisRun $run,
    ) {}
}
