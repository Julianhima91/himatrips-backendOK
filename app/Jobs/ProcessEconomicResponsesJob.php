<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessEconomicResponsesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $batchId;

    private $adConfigId;

    private $minNights;

    public function __construct(string $batchId, $adConfigId, $minNights)
    {
        $this->batchId = $batchId;
        $this->adConfigId = $adConfigId;
        $this->minNights = $minNights;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $flights = Cache::get("$this->adConfigId:$this->batchId:cheap_flights");
        $flightsReturn = Cache::get("$this->adConfigId:$this->batchId:cheap_flights_return");

        if ($flights && $flightsReturn) {
            $cheapestCombination = null;
            $cheapestPrice = PHP_INT_MAX;

            foreach ($flights as $outbound) {
                $outboundDate = $outbound['date'];
                $outboundPrice = $outbound['price'];

                $returnDate = $outboundDate + $this->minNights;

                $return = collect($flightsReturn)->firstWhere('date', $returnDate);

                if (! $return) {
                    //                    Log::info("Skipping outbound date $outboundDate, no return flight found for date $returnDate.");
                    continue;
                }

                $returnPrice = $return['price'];
                $totalPrice = $outboundPrice + $returnPrice;

                //                Log::info("Checking combination: Outbound ($outboundDate) - {$outboundPrice}, Return ($returnDate) - {$returnPrice}, Total: {$totalPrice}");

                if ($totalPrice < $cheapestPrice) {
                    $cheapestPrice = $totalPrice;
                    $cheapestCombination = [
                        'outbound' => $outbound,
                        'return' => $return,
                        'total_price' => $totalPrice,
                    ];

                    //                    Log::info("New cheapest combination found: " . json_encode($cheapestCombination));
                }
            }

            if ($cheapestCombination) {
                Log::info('Final cheapest combination: '.json_encode($cheapestCombination));
            } else {
                Log::info('No valid flight combination found.');
            }

            Cache::forget("$this->adConfigId:$this->batchId:cheap_flights");
            Cache::forget("$this->adConfigId:$this->batchId:cheap_flights_return");
            Cache::put("$this->adConfigId:$this->batchId:cheapest_combination", $cheapestCombination);

            Log::error('2025-02-'.Cache::get("$this->adConfigId:$this->batchId:cheapest_combination")['outbound']['date']);
        }
    }
}
