<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CheapestDateEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $batchId;

    public $batchIds;

    public $adConfigId;

    public $holidays;

    public $destinationId;

    public function __construct($batchId, $batchIds, $adConfigId, $holidays, $destinationId)
    {
        $this->batchId = $batchId;
        $this->batchIds = $batchIds;
        $this->adConfigId = $adConfigId;
        $this->holidays = $holidays;
        $this->destinationId = $destinationId;
    }
}
