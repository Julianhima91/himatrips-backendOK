<?php

namespace App\Jobs;

use App\Http\Integrations\GoFlightIntegration\Requests\OneWayDirectFlightCalendarRequest;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CheckEconomicFlightJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $origin_airport;

    private $destination_airport;

    private $date;

    private $airlineName;

    private $destination_origin_id;

    private $isReturnFlight;

    private $yearMonth;

    private $adConfigId;

    private $baseBatchId;

    public function __construct($origin_airport, $destination_airport, $yearMonth, $adConfigId, $baseBatchId, $isReturnFlight)
    {
        $this->origin_airport = $origin_airport;
        $this->destination_airport = $destination_airport;
        //        $this->airlineName = $airlineName;
        //        $this->destination_origin_id = $destination_origin_id;
        $this->isReturnFlight = $isReturnFlight;
        $this->yearMonth = $yearMonth;
        $this->adConfigId = $adConfigId;
        $this->baseBatchId = $baseBatchId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $flightRequest = new OneWayDirectFlightCalendarRequest;
        $logger = Log::channel('economic');

        //        Log::info($this->origin_airport?->rapidapi_id);
        //        Log::info($this->destination_airport?->rapidapi_id);
        //        Log::info('ECONOMIC===================');

        $flightRequest->query()->merge([
            'fromEntityId' => $this->origin_airport?->rapidapi_id ?? null,
            'toEntityId' => $this->destination_airport?->rapidapi_id ?? null,
            'yearMonth' => $this->yearMonth,
        ]);

        try {
            $response = $flightRequest->send();

            $grids = $response->json()['data']['PriceGrids']['Grid'][0];

            $outboundCheapMonthlyFlights = [];

            foreach ($grids as $index => $grid) {
                if ($grid['DirectOutboundAvailable'] && isset($grid['Direct'])) {
                    $outboundCheapMonthlyFlights[] = [
                        'price' => $grid['Direct']['Price'],
                        'is_direct' => true,
                        'date' => str_pad($index + 1, 2, '0', STR_PAD_LEFT),
                    ];
                } elseif (isset($grid['Indirect'])) {
                    $outboundCheapMonthlyFlights[] = [
                        'price' => $grid['Indirect']['Price'],
                        'is_direct' => false,
                        'date' => str_pad($index + 1, 2, '0', STR_PAD_LEFT),
                    ];
                }
            }

            //            foreach ($grids as $index => $grid) {
            //                $outboundCheapMonthlyFlights[] = [
            //                    'price' => $grid[$grid['DirectOutboundAvailable'] ? 'Direct' : 'Indirect']['Price'],
            //                    'is_direct' => $grid['DirectOutboundAvailable'],
            //                    'date' => $index+1
            //                ];
            //            }
            //            Log::error(print_r($outboundCheapMonthlyFlights, true));

            //            $logger->info("first job $this->baseBatchId");
            //            $logger->warning($outboundCheapMonthlyFlights);
            if ($this->isReturnFlight) {
                Cache::put("$this->adConfigId:$this->baseBatchId:cheap_flights", $outboundCheapMonthlyFlights, now()->addMinutes(180));
            } else {
                Cache::put("$this->adConfigId:$this->baseBatchId:cheap_flights_return", $outboundCheapMonthlyFlights, now()->addMinutes(180));
            }

        } catch (\Exception $e) {
            $logger = Log::channel('economic');

            $logger->error('!!!ERROR!!!');
            $logger->error($e->getMessage());
        }
    }
}
