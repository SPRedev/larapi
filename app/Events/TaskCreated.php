<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The task instance.
     *
     * @var \stdClass
     */
    public $task;

    /**
     * Create a new event instance.
     *
     * @param  \stdClass  $task
     * @return void
     */
    public function __construct($task)
    {
        $this->task = $task;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        // This defines the public channel name that your Flutter app is listening to.
        return new Channel('tasks');
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs()
    {
        // This defines the event name that your Flutter app is listening for.
        return 'TaskCreated';
    }
}
