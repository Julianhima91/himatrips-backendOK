<?php

namespace App\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessEconomicResponsesJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $baseBatchId;

    private $adConfigId;

    private $minNights;

    public function __construct(string $batchId, $adConfigId, $minNights)
    {
        $this->baseBatchId = $batchId;
        $this->adConfigId = $adConfigId;
        $this->minNights = $minNights;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $logger = Log::channel('economic');

        $flights = Cache::get("$this->adConfigId:$this->baseBatchId:cheap_flights");
        $flightsReturn = Cache::get("$this->adConfigId:$this->baseBatchId:cheap_flights_return");
        //        $logger->info("second job $this->baseBatchId");
        //        $logger->warning($flights);
        //        $logger->warning($flightsReturn);

        if ($flights && $flightsReturn) {
            $cheapestCombination = null;
            $cheapestPrice = PHP_INT_MAX;

            foreach ($flights as $outbound) {
                $outboundDate = $outbound['date'];
                $outboundPrice = $outbound['price'];

                $returnDate = $outboundDate + $this->minNights;

                $return = collect($flightsReturn)->firstWhere('date', $returnDate);

                if (! $return) {
                    //                    $logger->info("Skipping outbound date $outboundDate, no return flight found for date $returnDate.");
                    continue;
                }

                $returnPrice = $return['price'];
                $totalPrice = $outboundPrice + $returnPrice;

                //                $logger->info("Checking combination: Outbound ($outboundDate) - {$outboundPrice}, Return ($returnDate) - {$returnPrice}, Total: {$totalPrice}");

                if ($totalPrice < $cheapestPrice) {
                    $cheapestPrice = $totalPrice;
                    $cheapestCombination = [
                        'outbound' => $outbound,
                        'return' => $return,
                        'total_price' => $totalPrice,
                    ];

                    //                    $logger->info("New cheapest combination found: " . json_encode($cheapestCombination));
                }
            }

            if ($cheapestCombination) {
                $logger->info('Final cheapest combination: '.json_encode($cheapestCombination));

                Cache::put("$this->adConfigId:$this->baseBatchId:cheapest_combination", $cheapestCombination, now()->addMinutes(180));

                $logger->error('Outbound Date: '.Cache::get("$this->adConfigId:$this->baseBatchId:cheapest_combination")['outbound']['date']);
                $logger->error('Return Date: '.Cache::get("$this->adConfigId:$this->baseBatchId:cheapest_combination")['return']['date']);

            } else {
                $logger->info('No valid flight combination found.');
            }

            Cache::forget("$this->adConfigId:$this->baseBatchId:cheap_flights");
            Cache::forget("$this->adConfigId:$this->baseBatchId:cheap_flights_return");
        }
    }
}
