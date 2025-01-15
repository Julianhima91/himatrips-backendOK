<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessResponsesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $batchId;

    public function __construct(string $batchId)
    {
        $this->batchId = $batchId;
    }

    public function handle()
    {
        $flights = Cache::get("batch:{$this->batchId}:flights");
        $hotels = Cache::get("batch:{$this->batchId}:hotels");

        if ($flights && $hotels) {
            Log::info("Aggregated Response for batch {$this->batchId}");
            //todo: logic around saving

        } else {
            Log::error("Missing data for batch {$this->batchId}");
        }
    }
}
