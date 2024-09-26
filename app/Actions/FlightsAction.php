<?php

namespace App\Actions;

use App\Events\LiveSearchFailed;
use App\Models\FlightData;
use App\Models\PackageConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class FlightsAction
{
    public function handle($date, $destination, $batchId, $return_date)
    {
        $outbound_flight = Cache::get('flight_'.$date);

        //filter the flights as per the destination configuration
        //if destination has is_direct_flight set to true, we need to return only direct flights
        //if prioritize_morning_flights is set to true, we need to check if the flight is between the morning_flight_start_time and morning_flight_end_time
        //if we can find such flights we need to return them, but if we don't we still need to return the flights
        //if max_stop_count is not 0, we need to return only flights with stop count less than or equal to max_stop_count
        // and with max_wait_time less than or equal to max_wait_time

        //filter for direct flights
        $outbound_flight_direct = $outbound_flight->filter(function ($flight) {
            if ($flight == null) {
                return false;
            }

            return $flight->stopCount === 0;
        });

        //if we have direct flights, keep only direct flights
        if ($outbound_flight_direct->isNotEmpty()) {
            $outbound_flight = $outbound_flight_direct;
        }

        $outbound_flight_morning = $outbound_flight->when($destination->prioritize_morning_flights, function (Collection $collection) use ($destination) {
            return $collection->filter(function ($flight) use ($destination) {
                if ($flight == null) {
                    return false;
                }
                if ($destination->morning_flight_start_time && $destination->morning_flight_end_time) {
                    $departure = Carbon::parse($flight->departure);

                    // Create Carbon instances for start and end times on the same day as the departure
                    $morningStart = Carbon::parse($flight->departure->format('Y-m-d').' '.$destination->morning_flight_start_time);
                    $morningEnd = Carbon::parse($flight->departure->format('Y-m-d').' '.$destination->morning_flight_end_time);

                    // Now check if the departure time is between these two times
                    return $departure->between($morningStart, $morningEnd);
                }

                return true;
            });
        });

        //if we have morning flights, find the cheapest one
        if ($outbound_flight_morning->isNotEmpty()) {
            $outbound_flight = $outbound_flight_morning;
        }

        $outbound_flight = $outbound_flight->when($destination->max_stop_count !== 0, function (Collection $collection) use ($destination) {
            return $collection->filter(function ($flight) use ($destination) {
                if ($flight == null) {
                    return false;
                }

                return ! ($flight->stopCount <= $destination->max_stop_count &&
                        $flight->stopCount > 0) || $flight->timeBetweenFlights[0] <= $destination->max_wait_time;
            });
        });

        $outbound_flight = $outbound_flight->sortBy([
            ['stopCount', 'asc'],
            ['price', 'asc'],
        ]);

        //if collection is empty return early and broadcast failure
        if ($outbound_flight->isEmpty()) {
            broadcast(new LiveSearchFailed('No flights found', $batchId));

            return;
        }

        $packageConfig = PackageConfig::query()
            ->whereHas('destination_origin', function ($query) {
                $query->where([
                    ['destination_id', request()->destination_id],
                    ['origin_id', request()->origin_id],
                ]);
            })->first();

        //if morning flights are not empty get first otherwise get the first from the filtered flights
        $first_outbound_flight = $outbound_flight->first();

        $outbound_flight_hydrated = FlightData::create([
            'price' => $first_outbound_flight->price,
            'departure' => $first_outbound_flight->departure,
            'arrival' => $first_outbound_flight->arrival,
            'airline' => $first_outbound_flight->airline,
            'stop_count' => $first_outbound_flight->stopCount,
            'origin' => $first_outbound_flight->origin,
            'destination' => $first_outbound_flight->destination,
            'adults' => $first_outbound_flight->adults,
            'children' => $first_outbound_flight->children,
            'infants' => $first_outbound_flight->infants,
            'extra_data' => json_encode($first_outbound_flight),
            'segments' => $first_outbound_flight->segments,
            //todo: Default package config id?
            'package_config_id' => $packageConfig->id,
        ]);

        $inbound_flight = Cache::get('flight_'.$return_date);

        $inbound_flight_direct = $inbound_flight->filter(function ($flight) {
            if ($flight == null) {
                return false;
            }

            return $flight->stopCount === 0;
        });

        //if we have direct flights, keep only direct flights
        if ($inbound_flight_direct->isNotEmpty()) {
            $inbound_flight = $inbound_flight_direct;
        }

        $inbound_flight_evening = $inbound_flight->when($destination->prioritize_evening_flights, function (Collection $collection) use ($destination) {
            return $collection->filter(function ($flight) use ($destination) {
                if ($flight == null) {
                    return false;
                }
                if ($destination->evening_flight_start_time && $destination->evening_flight_end_time) {
                    $departure = Carbon::parse($flight->departure);

                    // Create Carbon instances for start and end times on the same day as the departure
                    $eveningStart = Carbon::parse($flight->departure->format('Y-m-d').' '.$destination->evening_flight_start_time);
                    $eveningEnd = Carbon::parse($flight->departure->format('Y-m-d').' '.$destination->evening_flight_end_time);

                    // Now check if the departure time is between these two times
                    return $departure->between($eveningStart, $eveningEnd);
                }

                return true;
            });
        });

        //if we have morning flights, find the cheapest one
        if ($inbound_flight_evening->isNotEmpty()) {
            $inbound_flight = $inbound_flight_evening;
        }

        $inbound_flight = $inbound_flight->when($destination->max_stop_count !== 0, function (Collection $collection) {
            return $collection->filter(function ($flight) {
                if ($flight == null) {
                    return false;
                }

                return ! ($flight->stopCount <= 1 &&
                        $flight->stopCount > 0) || $flight->timeBetweenFlights[0] <= 360;
            });
        });

        $inbound_flight = $inbound_flight->sortBy([
            ['stopCount', 'asc'],
            ['price', 'asc'],
        ]);

        //if collection is empty return early and broadcast failure
        if ($inbound_flight->isEmpty()) {
            broadcast(new LiveSearchFailed('No flights found', $batchId));

            return;
        }

        $first_inbound_flight = $inbound_flight->first();

        $inbound_flight_hydrated = FlightData::create([
            'price' => $first_inbound_flight->price,
            'departure' => $first_inbound_flight->departure_flight_back,
            'arrival' => $first_inbound_flight->arrival_flight_back,
            'airline' => $first_inbound_flight->airline_back,
            'stop_count' => $first_inbound_flight->stopCount_back,
            'origin' => $first_inbound_flight->origin_back,
            'destination' => $first_inbound_flight->destination_back,
            'adults' => $first_inbound_flight->adults,
            'children' => $first_inbound_flight->children,
            'infants' => $first_inbound_flight->infants,
            'extra_data' => json_encode($first_inbound_flight),
            'segments' => $first_inbound_flight->segments,
            //todo: Add a package config id
            'package_config_id' => $packageConfig->id,
        ]);

        return [$outbound_flight_hydrated, $inbound_flight_hydrated];
    }
}
