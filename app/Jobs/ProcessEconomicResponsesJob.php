<?php

namespace App\Jobs;

use App\Models\DestinationOrigin;
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

    private $adConfig;

    private $destination;

    public function __construct(string $batchId, $adConfigId, $minNights, $adConfig, $destination)
    {
        $this->baseBatchId = $batchId;
        $this->adConfigId = $adConfigId;
        $this->minNights = $minNights;
        $this->adConfig = $adConfig;
        $this->destination = $destination;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $logger = Log::channel('economic');

        $logger->info("Processing batch: $this->baseBatchId for adConfig: $this->adConfigId with minNights: $this->minNights");

        $flights = Cache::get("$this->adConfigId:$this->baseBatchId:cheap_flights");
        $flightsReturn = Cache::get("$this->adConfigId:$this->baseBatchId:cheap_flights_return");

        $logger->info('Retrieved flights from cache - Outbound: '.($flights ? count($flights) : 0).', Return: '.($flightsReturn ? count($flightsReturn) : 0));

        if ($flights && $flightsReturn) {
            $destinationOrigin = DestinationOrigin::where('origin_id', $this->adConfig->origin_id)
                ->where('destination_id', $this->destination->id)
                ->first();

            $stops = $destinationOrigin->stops;
            $minNights = $destinationOrigin->min_nights;
            $maxNights = $destinationOrigin->max_nights;

            // Filter flights based on stops configuration
            $filteredOutboundFlights = $this->filterFlightsByStops($flights, $stops);
            $filteredReturnFlights = $this->filterFlightsByStops($flightsReturn, $stops);

            $logger->info('After filtering by stops - Outbound: '.count($filteredOutboundFlights).', Return: '.count($filteredReturnFlights));

            if ($stops === 0) {
                $logger->info('Only considering direct flights (stops = 0)');
            } else {
                $logger->info('Considering all flights including connections (stops > 0)');
            }

            $cheapestCombination = null;
            $cheapestPrice = PHP_INT_MAX;
            $combinationsChecked = 0;
            $validCombinationsFound = 0;

            $logger->info('Starting to check flight combinations...');

            foreach ($filteredOutboundFlights as $outbound) {
                $outboundDate = $outbound['date'];
                $outboundPrice = $outbound['price'];

                for ($nights = $minNights; $nights <= $maxNights; $nights++) {
                    $returnDate = $outboundDate + $nights;

                    $return = collect($filteredReturnFlights)->firstWhere('date', $returnDate);

                    $combinationsChecked++;

                    if (! $return) {
                        $logger->debug("No return flight found for outbound date $outboundDate with $nights nights (return date would be $returnDate)");

                        continue;
                    }

                    $validCombinationsFound++;
                    $returnPrice = $return['price'];
                    $totalPrice = $outboundPrice + $returnPrice;

                    $logger->debug("Valid combination #$validCombinationsFound: Outbound {$outboundDate} + $nights nights = Return {$returnDate} | Prices: \${outboundPrice} + \${returnPrice} = \${totalPrice} | Direct: ".($outbound['is_direct'] ? 'Out-Yes' : 'Out-No').'/'.($return['is_direct'] ? 'Ret-Yes' : 'Ret-No'));

                    if ($totalPrice < $cheapestPrice) {
                        $cheapestPrice = $totalPrice;
                        $cheapestCombination = [
                            'outbound' => $outbound,
                            'return' => $return,
                            'nights' => $nights,
                            'total_price' => $totalPrice,
                        ];

                        $logger->info("NEW CHEAPEST found: $totalPrice € (Outbound: {$outboundDate}, Return: {$returnDate}, Nights: $nights)");
                    }
                }
            }

            $logger->info("Combination analysis complete - Checked: $combinationsChecked, Valid: $validCombinationsFound");

            if ($cheapestCombination) {
                $logger->info('=== FINAL RESULT ===');
                $logger->info('Cheapest combination found: '.json_encode($cheapestCombination));
                $logger->info("Outbound: Date {$cheapestCombination['outbound']['date']}, Price {$cheapestCombination['outbound']['price']} €, Direct: ".($cheapestCombination['outbound']['is_direct'] ? 'Yes' : 'No'));
                $logger->info("Return: Date {$cheapestCombination['return']['date']}, Price {$cheapestCombination['return']['price']} €, Direct: ".($cheapestCombination['return']['is_direct'] ? 'Yes' : 'No'));
                $logger->info("Total Price: {$cheapestCombination['total_price']} €");

                Cache::put("$this->adConfigId:$this->baseBatchId:cheapest_combination", $cheapestCombination, now()->addMinutes(180));

                $logger->info('Cheapest combination cached successfully');
            } else {
                $logger->warning('No valid flight combination found after filtering and processing');
                $logger->info('Possible reasons: No return flights available for the calculated return dates, or all flights filtered out by stops requirement');
            }

            Cache::forget("$this->adConfigId:$this->baseBatchId:cheap_flights");
            Cache::forget("$this->adConfigId:$this->baseBatchId:cheap_flights_return");

            $logger->info("Cache cleared for batch: $this->baseBatchId");
        } else {
            $logger->error('Missing flight data in cache - Outbound: '.($flights ? 'Found' : 'Missing').', Return: '.($flightsReturn ? 'Found' : 'Missing'));
        }

        $logger->info("ProcessEconomicResponsesJob completed for batch: $this->baseBatchId");
    }

    /**
     * Filter flights based on stops configuration
     */
    private function filterFlightsByStops(array $flights, $stops): array
    {
        $logger = Log::channel('economic');

        if ($stops === 0) {
            $directFlights = array_filter($flights, function ($flight) {
                return $flight['is_direct'] === true;
            });

            $logger->info('Filtering for direct flights only - Original: '.count($flights).', After filter: '.count($directFlights));

            return $directFlights;
        }

        $logger->info("No filtering needed (stops = {$stops}) - Keeping all ".count($flights).' flights');

        return $flights;
    }
}
