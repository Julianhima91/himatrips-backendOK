<?php

namespace App\Console\Commands;

use App\Models\Package;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckPackageFlights extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:package-flights';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Checks if packages have flights with multiple stops when there's a direct one";

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $now = now();
        $yesterday = $now->copy()->subDay();

        $recentSearches = Package::whereBetween('created_at', [$yesterday, $now])
            ->get();

        foreach ($recentSearches as $search) {
            $inboundFlight = $search->inboundFlight;
            $outboundFlight = $search->outboundFlight;

            if ($inboundFlight->stop_count > 0 || $outboundFlight->stop_count > 0) {
                foreach (json_decode($inboundFlight->all_flights, true) as $flight) {
                    if ($flight['stopCount'] === 0 || $flight['stopCount_back'] === 0) {
                        Log::channel('daily')->warning('Direct Flight detected', [
                            'search_id' => $search->id,
                            'inbound_flight_stops' => $inboundFlight->stop_count,
                            'outbound_flight_stops' => $outboundFlight->stop_count,
                            'api_stop_count' => $flight['stopCount'],
                            'api_stop_count_back' => $flight['stopCount_back'],
                        ]);
                    }
                }
            } else {
                Log::channel('daily')->info('No Direct Flight detected');
            }
        }
        $this->info('Package flight validation completed.');
    }
}
