<?php

namespace App\Actions;

use App\Http\Requests\CheckFlightAvailabilityRequest;
use App\Jobs\CheckFlightAvailabilityJob;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\PackageConfig;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;

class CheckFlightAvailability
{
    public function handle(CheckFlightAvailabilityRequest $request): bool
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
            CheckFlightAvailabilityJob::dispatch($origin_airport, $destination_airport, $date->format('Y-m-d'), $airlineName, $packageConfig->destination_origin_id, false);
        }

        $returnPeriod = CarbonPeriod::create(Carbon::parse($request->from_date)->addDay(), Carbon::parse($request->to_date)->addDay());

        foreach ($returnPeriod as $date) {
            CheckFlightAvailabilityJob::dispatch($destination_airport, $origin_airport, $date->format('Y-m-d'), $airlineName, $packageConfig->destination_origin_id, true);
        }

        return true;
    }
}
