<?php

namespace App\Console\Commands;

use App\Models\Package;
use App\Models\PackageConfig;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateClientSearches extends Command
{
    protected $signature = 'client-searches:update';

    protected $description = 'Populate client_searches table with searches and precomputed URLs';

    public function handle()
    {
        $this->info('Updating client_searches table...');

        DB::table('client_searches')->truncate();

        $packages = Package::query()
            ->with('hotelData', 'inboundFlight')
            ->select('id', 'package_config_id', 'batch_id', 'hotel_data_id', 'inbound_flight_id', 'created_at')
            ->whereIn('id', function ($query) {
                $query->select(DB::raw('MIN(id)'))
                    ->from('packages')
                    ->groupBy('batch_id');
            })
            ->orderBy('created_at', 'desc')
            ->get();

        $count = 0;
        Log::info("Total Packages: " . count($packages));

        $insertData = [];

        foreach ($packages as $package) {
            $packageConfig = PackageConfig::with(['destination_origin.origin', 'destination_origin.destination'])
                ->find($package->package_config_id);

            if (! $packageConfig) {
                $count++;
                Log::info("Package ID: " . $package->id . " Package Config ID: " . $package->package_config_id . " Package Config Not Found");;

                continue;
            }

            $originName = $packageConfig->destination_origin->origin->name;
            $originId = $packageConfig->destination_origin->origin->id;
            $destinationName = $packageConfig->destination_origin->destination->name;
            $destinationId = $packageConfig->destination_origin->destination->id;
            $hotelData = $package->hotelData;

            $url = config('app.front_url').'/search-'.
                strtolower(str_replace(' ', '-', $originName)).'-to-'.
                strtolower(str_replace(' ', '-', $destinationName)).'?'.
                base64_encode(http_build_query([
                    'batch_id' => $package->batch_id,
                    'nights' => $hotelData->number_of_nights,
                    'checkin_date' => $hotelData->check_in_date,
                    'origin_id' => $packageConfig->destination_origin->origin->id,
                    'destination_id' => $packageConfig->destination_origin->destination->id,
                    'page' => 1,
                    'rooms' => $hotelData->room_object,
                    'directFlightsOnly' => $package->inboundFlight->stop_count === 0 ? 'true' : 'false',
                    'adults' => $hotelData->adults,
                    'children' => $hotelData->children,
                    'infants' => $hotelData->infants,
                    'refresh' => 0,
                ]));

            $insertData[] = [
                'package_id' => $package->id,
                'inbound_flight_id' => $package->inbound_flight_id,
                'origin_name' => $originName,
                'origin_id' => $originId,
                'destination_name' => $destinationName,
                'destination_id' => $destinationId,
                'package_config_id' => $package->package_config_id,
                'batch_id' => $package->batch_id,
                'adults' => $hotelData->adults,
                'children' => $hotelData->children,
                'infants' => $hotelData->infants,
                'number_of_nights' => $hotelData->number_of_nights,
                'checkin_date' => $hotelData->check_in_date,
                'rooms' => json_encode($hotelData->room_object),
                'direct_flights_only' => $package->inboundFlight->stop_count === 0,
                'url' => $url,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'package_created_at' => $package->created_at,
            ];
        }

        foreach (array_chunk($insertData, 500) as $chunk) {
            Log::info('Inserting chunk of data');
            DB::table('client_searches')->insert($chunk);
        }

        Log::info("Total Packages not found: " . count($count));

        $this->info('Client searches updated successfully.');
    }
}
