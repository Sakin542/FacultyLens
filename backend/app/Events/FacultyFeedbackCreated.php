<?php

namespace App\Events;


/** STEP 47: a faculty member submitted feedback/decision on an AI recommendation. */
class FacultyFeedbackCreated extends DomainEvent
{
    public function __construct(
        public \App\Models\Recommendation $recommendation,
        public \App\Models\User $submitter,
        public string $decision,
    ) {}
}
