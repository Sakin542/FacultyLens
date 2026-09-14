<?php

namespace App\Events;


/** STEP 47: a platform-level incident that administrators (or a given set of users) must see. */
class SystemAlertRaised extends DomainEvent
{
    public function __construct(
        /** @var iterable<\App\Models\User|int> */
        public iterable $recipients,
        public string $title,
        public string $message,
        public array $data = [],
        public ?string $dedupeKey = null,
        public string $severity = 'WARNING',
    ) {}
}
