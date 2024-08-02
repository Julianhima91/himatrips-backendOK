<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LiveSearchCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $packages;

    public $batch_id;

    public $min;

    public $max;

    /**
     * Create a new event instance.
     */
    public function __construct($packages, $batch_id, $min, $max)
    {
        $this->packages = $packages;
        $this->batch_id = $batch_id;
        $this->min = $min;
        $this->max = $max;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('search'),
        ];
    }
}
