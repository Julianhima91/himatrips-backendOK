<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JobChainCompletedEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $batchId;

    public $batchIds;

    public function __construct($batchId, $batchIds)
    {
        $this->batchId = $batchId;
        $this->batchIds = $batchIds;
    }
}
