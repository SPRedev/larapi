<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public $payload;

    public function __construct($payload)
    {
        $this->payload = $payload;
    }

    public function broadcastOn()
    {
        // This MUST match the channel in your Flutter app
        return new Channel('tasks');
    }

    public function broadcastAs()
    {
        // This MUST match the event name in your Flutter app
        return 'TaskCreated'; // We reuse the same event name
    }
}
