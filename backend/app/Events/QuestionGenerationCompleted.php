<?php

namespace App\Events;


/** STEP 47: a constrained question generation request produced drafts (drafts ≠ approved). */
class QuestionGenerationCompleted extends DomainEvent
{
    public function __construct(
        public \App\Models\QuestionGenerationRequest $request,
    ) {}
}
