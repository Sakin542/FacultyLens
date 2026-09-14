<?php

namespace App\Events;


/** STEP 47: AI recommendations were persisted for a completed analysis report. */
class RecommendationsCreated extends DomainEvent
{
    public function __construct(
        public \App\Models\AnalysisReport $report,
        public int $count,
    ) {}
}
