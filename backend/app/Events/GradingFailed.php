<?php

namespace App\Events;


/** STEP 47: an AI grading suggestion could not be produced. */
class GradingFailed extends DomainEvent
{
    public function __construct(
        public \App\Models\AiGradingResult $result,
    ) {}
}
