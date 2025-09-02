<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FlightDataUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $flights;

    public $batchId;

    public function __construct(string $batchId, array $flights)
    {
        $this->batchId = $batchId;
        $this->flights = $flights;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): Channel
    {
        return new Channel('flights');
    }

    public function broadcastWith(): array
    {
        return [
            'batchId' => $this->batchId,
            'flights' => $this->flights,
        ];
    }
}
