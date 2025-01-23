<?php

namespace App\Jobs;

use App\Models\AdConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessFlightsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $request;

    private $adConfigId;

    public function __construct(array $request, $adConfigId)
    {
        $this->request = $request;
        $this->adConfigId = $adConfigId;
    }

    public function handle(): void
    {
        $batchId = $this->request['batch_id'];

        dispatch_sync(new LiveSearchFlightsApi2($this->request['origin_airport'], $this->request['destination_airport'], $this->request['date'], $this->request['return_date'], $this->request['origin_airport'], $this->request['destination_airport'], $this->request['rooms'][0]['adults'], $this->request['rooms'][0]['children'], $this->request['rooms'][0]['infants'], $batchId));
        dispatch_sync(new LiveSearchFlights($this->request['date'], $this->request['return_date'], $this->request['origin_airport'], $this->request['destination_airport'], $this->request['rooms'][0]['adults'], $this->request['rooms'][0]['children'], $this->request['rooms'][0]['infants'], $batchId));

        $flights = Cache::get("batch:{$batchId}:flights");

        ray('FLIGHTS CACHE')->purple();
        ray($flights)->purple();
        if ($flights) {
            //            Log::info("Flights data successfully cached and retrieved for batch: {$batchId}");
            $adConfig = AdConfig::find($this->adConfigId);

            if (in_array('cheapest_date', $adConfig->extra_options)) {
                $batchIds = Cache::get('batch_ids');
                $batchIds[] = (string) $batchId;
                Cache::put('batch_ids', $batchIds, 90);
            }

            $csvCache = Cache::get('create_csv');
            $csvCache[] = (string) $batchId;
            Cache::put('create_csv', $csvCache, 90);

        } else {
            Log::warning("Flights data not found in cache for batch: {$batchId}");
        }
    }
}
