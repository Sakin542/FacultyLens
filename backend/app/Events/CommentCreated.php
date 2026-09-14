<?php

namespace App\Events;


/** STEP 47: a collaboration comment/reply was created; $mentions are verified member ids, $participants the thread participants. */
class CommentCreated extends DomainEvent
{
    public function __construct(
        public \App\Models\CollaborationComment $comment,
        public \App\Models\Course $course,
        public \App\Models\User $actor,
        /** @var int[] */
        public array $mentions,
        /** @var int[] */
        public array $participants,
    ) {}
}
