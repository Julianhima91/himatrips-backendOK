<?php

namespace App\Actions;

use App\Http\Integrations\GoFlightIntegration\Requests\OneWayDirectFlightRequest;
use App\Http\Requests\CheckFlightAvailabilityRequest;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\DirectFlightAvailability;
use App\Models\PackageConfig;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
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
            $this->searchDirectFlight($origin_airport, $destination_airport, $date->format('Y-m-d'), $airlineName, $packageConfig->destination_origin_id, false);
        }

        $returnPeriod = CarbonPeriod::create(Carbon::parse($request->from_date)->addDay(), Carbon::parse($request->to_date)->addDay());

        foreach ($returnPeriod as $date) {
            $this->searchDirectFlight($destination_airport, $origin_airport, $date->format('Y-m-d'), $airlineName, $packageConfig->destination_origin_id, true);
        }

        return true;
    }

    private function searchDirectFlight($origin_airport, $destination_airport, $date, $airlineName, $destination_origin_id, $is_return_flight)
    {
        $flightRequest = new OneWayDirectFlightRequest;

        $flightRequest->query()->merge([
            'fromEntityId' => $origin_airport->rapidapi_id,
            'toEntityId' => $destination_airport->rapidapi_id,
            'departDate' => $date,
            'stops' => 'direct',
        ]);

        try {
            $response = $flightRequest->send();

            $itineraries = $response->json()['data']['itineraries'] ?? [];

            if ($this->hasDirectFlight($itineraries, $airlineName)) {
                DirectFlightAvailability::updateOrCreate([
                    'date' => $date,
                    'destination_origin_id' => $destination_origin_id,
                    'is_return_flight' => $is_return_flight,
                ]);

                Log::info(($is_return_flight ? 'Return' : 'Outbound').' direct flight available on date: '.$date);
            } else {
                Log::info('No itineraries found for date: '.$date);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('!!!ERROR!!!');
            Log::error($e->getMessage());

            return false;
        }
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
