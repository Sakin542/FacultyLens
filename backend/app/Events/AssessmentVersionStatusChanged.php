<?php

namespace App\Events;


/** STEP 47: an assessment version was created / submitted / approved / finalized / archived / restored by faculty. */
class AssessmentVersionStatusChanged extends DomainEvent
{
    public function __construct(
        public \App\Models\AssessmentVersion $version,
        public string $action,
        public \App\Models\User $actor,
    ) {}
}
