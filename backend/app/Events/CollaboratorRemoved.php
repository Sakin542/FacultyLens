<?php

namespace App\Events;


/** STEP 47: a collaborator's access to a course was revoked. */
class CollaboratorRemoved extends DomainEvent
{
    public function __construct(
        public \App\Models\Course $course,
        public \App\Models\User $member,
        public \App\Models\User $actor,
        public string $role,
    ) {}
}
