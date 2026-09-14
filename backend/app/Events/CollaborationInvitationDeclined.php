<?php

namespace App\Events;


/** STEP 47: an invitee declined a collaboration invitation. */
class CollaborationInvitationDeclined extends DomainEvent
{
    public function __construct(
        public \App\Models\CourseCollaborationInvitation $invitation,
        public \App\Models\User $user,
    ) {}
}
