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

    private $tempCache;

    public function __construct(array $request, $adConfigId, $tempCache)
    {
        $this->request = $request;
        $this->adConfigId = $adConfigId;
        $this->tempCache = $tempCache;
    }

    public function handle(): void
    {
        $channel = match ($this->tempCache) {
            'weekend' => 'weekend',
            'economic' => 'economic',
            'holiday' => 'holiday',
            default => 'default',
        };

        $logger = Log::channel($channel);

        $batchId = $this->request['batch_id'];

        dispatch_sync(new LiveSearchFlightsApi2(
            $this->request['origin_airport'],
            $this->request['destination_airport'],
            $this->request['date'],
            $this->request['return_date'],
            $this->request['origin_airport'],
            $this->request['destination_airport'],
            $this->request['rooms'][0]['adults'] ?? 2,
            $this->request['rooms'][0]['children'] ?? 0,
            $this->request['rooms'][0]['infants'] ?? 0,
            $batchId
        ));

        //        dispatch_sync(new LiveSearchFlights($this->request['date'], $this->request['return_date'], $this->request['origin_airport'], $this->request['destination_airport'], $this->request['rooms'][0]['adults'], $this->request['rooms'][0]['children'], $this->request['rooms'][0]['infants'], $batchId));

        $flights = Cache::get("batch:{$batchId}:flights");

        if ($flights) {
            $adConfig = AdConfig::find($this->adConfigId);

            if (in_array('cheapest_date', $adConfig->extra_options)) {
                $batchIds = Cache::get("$adConfig->id:batch_ids");
                $batchIds[] = (string) $batchId;
                Cache::put("$adConfig->id:batch_ids", $batchIds, now()->addMinutes(120));
            }

            if ($this->tempCache == 'weekend') {
                $csvCache = Cache::get("$adConfig->id:weekend_create_csv");
                $csvCache[] = (string) $batchId;
                Cache::put("$adConfig->id:weekend_create_csv", $csvCache, now()->addMinutes(120));
            } else {
                $csvCache = Cache::get("$adConfig->id:create_csv");
                $csvCache[] = (string) $batchId;
                Cache::put("$adConfig->id:create_csv", $csvCache, now()->addMinutes(120));
            }
        } else {
            $logger->warning("Flights data not found in cache for batch: {$batchId}");
        }
    }
}
