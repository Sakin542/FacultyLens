<?php

namespace App\Events;


/** STEP 47: an AI rubric draft was persisted (DRAFT — faculty approval still required). */
class RubricGenerated extends DomainEvent
{
    public function __construct(
        public \App\Models\Rubric $rubric,
        public \App\Models\User $actor,
    ) {}
}
