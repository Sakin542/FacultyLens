<?php

namespace App\Events;


/** STEP 47: AI rubric generation failed for a question. */
class RubricGenerationFailed extends DomainEvent
{
    public function __construct(
        public \App\Models\Question $question,
        public \App\Models\User $actor,
    ) {}
}
