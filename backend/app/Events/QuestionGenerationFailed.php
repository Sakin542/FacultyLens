<?php

namespace App\Events;


/** STEP 47: a constrained question generation request failed. */
class QuestionGenerationFailed extends DomainEvent
{
    public function __construct(
        public \App\Models\QuestionGenerationRequest $request,
    ) {}
}
