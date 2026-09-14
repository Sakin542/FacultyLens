<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * STEP 47: base for FacultyLens domain events consumed by the notification listeners.
 * Events are dispatched synchronously after the domain state has been persisted; the listeners
 * only queue notifications, so a listener failure can never roll back or fail the operation.
 */
abstract class DomainEvent
{
    use Dispatchable, SerializesModels;
}
