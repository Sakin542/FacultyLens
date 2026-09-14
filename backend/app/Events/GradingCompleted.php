<?php

namespace App\Events;


/** STEP 47: an AI grading suggestion for one student answer is ready for faculty review. */
class GradingCompleted extends DomainEvent
{
    public function __construct(
        public \App\Models\AiGradingResult $result,
    ) {}
}
