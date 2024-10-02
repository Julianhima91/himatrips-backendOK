<?php

namespace App\Actions;

use App\Http\Integrations\GoFlightIntegration\Requests\OneWayDirectFlightRequest;
use App\Http\Requests\CheckFlightAvailabilityRequest;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\DirectFlightAvailability;
use App\Models\PackageConfig;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Log;

class CheckFlightAvailability
{
    public function handle(CheckFlightAvailabilityRequest $request)
    {
        $packageConfig = PackageConfig::find($request->package_config_id);
        $origin = $packageConfig->destination_origin->origin;
        $destination = $packageConfig->destination_origin->destination;

        $origin_airport = Airport::query()->where('origin_id', $origin->id)->first();
        $destination_airport = Airport::query()->whereHas('destinations', function ($query) use ($destination) {
            $query->where('destination_id', $destination->id);
        })->first();

        $airlineName = $request->airline_id ? Airline::find($request->airline_id)->nameAirline : null;

        $period = CarbonPeriod::create($request->from_date, $request->to_date);

        foreach ($period as $date) {
            $request = new OneWayDirectFlightRequest;
            $date = $date->format('Y-m-d');

            $request->query()->merge([
                'fromEntityId' => $origin_airport->rapidapi_id,
                'toEntityId' => $destination_airport->rapidapi_id,
                'departDate' => $date,
                'stops' => 'direct',
            ]);

            try {
                $response = $request->send();

                $itineraries = $response->json()['data']['itineraries'] ?? [];

                if ($this->hasDirectFlight($itineraries, $airlineName)) {
                    DirectFlightAvailability::updateOrCreate([
                        'date' => $date,
                        'destination_origin_id' => $packageConfig->destination_origin_id,
                    ]);
                    Log::info('Direct flight available on date: '.$date);
                } else {
                    Log::info('No itineraries found for date: '.$date);
                }
            } catch (\Exception $e) {
                Log::error('!!!ERROR!!!');
                Log::error($e->getMessage());

                return false;
            }
        }

        return true;
    }

    private function hasDirectFlight(array $itineraries, ?string $airlineName): bool
    {
        foreach ($itineraries as $itinerary) {
            if (! $airlineName ||
                ($itinerary['legs'][0]['carriers']['marketing'][0]['name'] === $airlineName)) {
                return true;
            }
        }

        return false;
    }
}
