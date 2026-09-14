<?php

namespace App\Events;


/** STEP 47: an invitee accepted a collaboration invitation. */
class CollaborationInvitationAccepted extends DomainEvent
{
    public function __construct(
        public \App\Models\CourseCollaborationInvitation $invitation,
        public \App\Models\CourseCollaborator $member,
        public \App\Models\User $user,
    ) {}
}
