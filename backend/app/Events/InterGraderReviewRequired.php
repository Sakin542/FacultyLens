<?php

namespace App\Events;


/** STEP 47: grading variation between faculty graders was detected (STEP 29 producer; none deployed yet). */
class InterGraderReviewRequired extends DomainEvent
{
    public function __construct(
        public \App\Models\Assessment $assessment,
        public ?int $questionId = null,
        public array $context = [],
    ) {}
}
