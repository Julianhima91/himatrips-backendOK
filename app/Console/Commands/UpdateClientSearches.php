<?php

namespace App\Console\Commands;

use App\Models\Package;
use App\Models\PackageConfig;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

        $insertData = [];

        foreach ($packages as $package) {
            $packageConfig = PackageConfig::with(['destination_origin.origin', 'destination_origin.destination'])
                ->find($package->package_config_id);

            if (! $packageConfig) {
                continue;
            }

            // Check if destination_origin and its relationships exist
            if (!$packageConfig->destination_origin || 
                !$packageConfig->destination_origin->origin || 
                !$packageConfig->destination_origin->destination) {
                continue;
            }

            $originName = $packageConfig->destination_origin->origin->name;
            $originId = $packageConfig->destination_origin->origin->id;
            $destinationName = $packageConfig->destination_origin->destination->name;
            $destinationId = $packageConfig->destination_origin->destination->id;
            $hotelData = $package->hotelData;

            // Skip if hotelData is missing
            if (!$hotelData) {
                continue;
            }

            $inboundFlight = $package->inboundFlight;
            $directFlightsOnly = $inboundFlight && $inboundFlight->stop_count === 0;

            $url = config('app.front_url').'/search-'.
                strtolower(str_replace(' ', '-', $originName)).'-to-'.
                strtolower(str_replace(' ', '-', $destinationName)).'?'.
                'query='.base64_encode(http_build_query([
                    'batch_id' => $package->batch_id,
                    'nights' => $hotelData->number_of_nights ?? 0,
                    'checkin_date' => $hotelData->check_in_date ?? '',
                    'origin_id' => $packageConfig->destination_origin->origin->id,
                    'destination_id' => $packageConfig->destination_origin->destination->id,
                    'page' => 1,
                    'rooms' => $hotelData->room_object ?? [],
                    'directFlightsOnly' => $directFlightsOnly ? 'true' : 'false',
                    'adults' => $hotelData->adults ?? 0,
                    'children' => $hotelData->children ?? 0,
                    'infants' => $hotelData->infants ?? 0,
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
                'adults' => $hotelData->adults ?? 0,
                'children' => $hotelData->children ?? 0,
                'infants' => $hotelData->infants ?? 0,
                'number_of_nights' => $hotelData->number_of_nights ?? 0,
                'checkin_date' => $hotelData->check_in_date ?? null,
                'rooms' => json_encode($hotelData->room_object ?? []),
                'direct_flights_only' => $directFlightsOnly,
                'url' => $url,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'package_created_at' => $package->created_at,
            ];
        }

        foreach (array_chunk($insertData, 500) as $chunk) {
            DB::table('client_searches')->insert($chunk);
        }

        $this->info('Client searches updated successfully.');
    }
}
