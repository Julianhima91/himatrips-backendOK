<?php

namespace App\Actions;

use App\Http\Requests\CheckFlightAvailabilityRequest;
use App\Models\DirectFlightAvailability;
use App\Models\FlightData;
use App\Models\PackageConfig;

class CheckFlightAvailability
{
    public function handle(CheckFlightAvailabilityRequest $request)
    {
        $packageConfig = PackageConfig::query()
            ->where('id', $request['package_config_id'])
            ->when($request['airline_id'], function ($query, $airline_id) {
                return $query->whereJsonContains('airlines', $airline_id);
            })->first();

        if (! isset($packageConfig)) {
            return false;
        }

        $flights = FlightData::query()
            ->where('package_config_id', $packageConfig->id)
            ->whereDate('departure', '>=', $request['from_date'])
            ->whereDate('departure', '<=', $request['to_date'])
            ->when($request['is_direct_flight'], function ($query) {
                return $query->where('stop_count', 0);
            })->get()
            ->groupBy(function ($flight) {
                return $flight->departure->toDateString();
            });

        foreach ($flights as $date => $flightsOnDate) {
            DirectFlightAvailability::updateOrCreate(
                [
                    'date' => $date,
                    'destination_origin_id' => $packageConfig->destination_origin_id,
                ],
            );
        }

        return true;
    }
}
