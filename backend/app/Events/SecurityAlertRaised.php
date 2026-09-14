<?php

namespace App\Events;


/** STEP 47: a security-relevant account event for one user (never carries secrets or technical detail). */
class SecurityAlertRaised extends DomainEvent
{
    public function __construct(
        public \App\Models\User $user,
        public string $title,
        public string $message,
        public array $data = [],
        public ?string $dedupeKey = null,
        public ?string $actionUrl = null,
    ) {}
}
