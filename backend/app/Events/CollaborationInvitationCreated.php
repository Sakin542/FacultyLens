<?php

namespace App\Events;


/** STEP 47: a course collaboration invitation was issued. */
class CollaborationInvitationCreated extends DomainEvent
{
    public function __construct(
        public \App\Models\CourseCollaborationInvitation $invitation,
        public \App\Models\User $inviter,
        public ?\App\Models\User $invitee,
    ) {}
}
