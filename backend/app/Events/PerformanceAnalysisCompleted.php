<?php

namespace App\Events;


/** STEP 47: a student performance snapshot completed (aggregate only — no per-student data in notifications). */
class PerformanceAnalysisCompleted extends DomainEvent
{
    public function __construct(
        public \App\Models\PerformanceAnalysisRun $run,
    ) {}
}
