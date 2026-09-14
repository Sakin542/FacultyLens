<?php

namespace App\Events;


/** STEP 47: a collaborator's role on a course changed. */
class CollaboratorRoleChanged extends DomainEvent
{
    public function __construct(
        public \App\Models\Course $course,
        public \App\Models\User $member,
        public \App\Models\User $actor,
        public string $from,
        public string $to,
    ) {}
}
