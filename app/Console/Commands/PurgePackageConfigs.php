<?php

namespace App\Console\Commands;

use App\Models\Airport;
use App\Models\DestinationOrigin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgePackageConfigs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package-configs:purge';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Purge all PackageConfig records with the same origin and destination, also
                              deleting all destination-origins that don't have airports.";

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting purge of same destination-origin package configs and no airport destination-origins...');

        $sameOriginDestinationDeletedCount = DB::table('package_configs')
            ->join('destination_origins', 'package_configs.destination_origin_id', '=', 'destination_origins.id')
            ->join('destinations', 'destination_origins.destination_id', '=', 'destinations.id')
            ->join('origins', 'destination_origins.origin_id', '=', 'origins.id')
            ->whereColumn('destinations.name', '=', 'origins.name')
            ->delete();

        $this->info("Purged {$sameOriginDestinationDeletedCount} package config(s) that had same destination & origin.");

        $destinationOrigins = DestinationOrigin::all();

        $noAirportCount = 0;
        foreach ($destinationOrigins as $destinationOrigin) {

            $destinationAirport = $destinationOrigin->destination->airports()->first();
            $originAirport = Airport::where('origin_id', $destinationOrigin->origin_id)->first();

            if (! $destinationAirport || ! $originAirport) {
                $destinationOrigin->delete();
                $noAirportCount++;
            }
        }

        $this->info("Purged {$noAirportCount} destination origins that had no airports");

        return Command::SUCCESS;
    }
}
