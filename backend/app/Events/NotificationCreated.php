<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * STEP 48: Real-time notification broadcast event.
 * Broadcasts to the authenticated user's private channel: users.{userId}.notifications
 * Payload contains only UI-required fields, never secrets or sensitive academic data.
 */
class NotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Notification $notification) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("users.{$this->notification->user_id}.notifications"),
        ];
    }

    /**
     * Broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'NotificationCreated';
    }

    /**
     * Get the data to broadcast.
     * Exposes only UI-required information via toApi().
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $payload = $this->notification->toApi();

        return array_merge($payload, [
            'notification' => $payload,
        ]);
    }
}

