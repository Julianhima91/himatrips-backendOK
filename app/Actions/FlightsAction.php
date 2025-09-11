<?php

namespace App\Actions;

use App\Events\LiveSearchFailed;
use App\Models\FlightData;
use App\Models\PackageConfig;
use App\Settings\MaxTransitTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FlightsAction
{
    public function handle($date, $destination, $batchId, $return_date, $origin_id, $destination_id)
    {
        $logger = Log::channel('livesearch');
        $outbound_flight = Cache::get("flight:{$batchId}:{$date}");

        // filter the flights as per the destination configuration
        // if destination has is_direct_flight set to true, we need to return only direct flights
        // if prioritize_morning_flights is set to true, we need to check if the flight is between the morning_flight_start_time and morning_flight_end_time
        // if we can find such flights we need to return them, but if we don't we still need to return the flights
        // if max_stop_count is not 0, we need to return only flights with stop count less than or equal to max_stop_count
        // and with max_wait_time less than or equal to max_wait_time

        $logger->info("========================================= $batchId");
        $logger->info('Filtering for batch id: '.$batchId.' starting ...');
        $logger->info("========================================= $batchId");
        $logger->info('Total for batch id: '.$batchId.' Before filter count: '.count($outbound_flight ?? []));

        // filter for direct flights
        $outbound_flight_direct = $outbound_flight->filter(function ($flight) {
            if ($flight == null) {
                return false;
            }

            return $flight->stopCount === 0 && $flight->stopCount_back === 0;
        });

        $packageConfig = PackageConfig::query()
            ->whereHas('destination_origin', function ($query) use ($destination_id, $origin_id) {
                $query->where([
                    ['destination_id', $destination_id],
                    ['origin_id', $origin_id],
                ]);
            })->first();

        // if we have direct flights, keep only direct flights
        if ($outbound_flight_direct->isNotEmpty()) {
            $logger->info('Direct Flight Found'." $batchId");
            $logger->info($batchId.' Count of flights with direct flight: '.count($outbound_flight_direct ?? []));
            $outbound_flight = $outbound_flight_direct;
        } else {
            $logger->warning('No Direct Flight Found'." $batchId");
            $logger->warning($batchId.' Count of flights with 1 or more stops: '.count($outbound_flight ?? []));

            $outboundStops = [];
            $outbound_flight_max_stops = $outbound_flight->filter(function ($flight) use ($packageConfig, &$outboundStops) {
                if ($flight == null) {
                    return false;
                }

                $outboundStops[] = $flight->stopCount;

                return $flight->stopCount <= $packageConfig->max_stop_count && $flight->stopCount_back <= $packageConfig->max_stop_count;
            });

            $minOutboundStops = ! empty($outboundStops) ? min($outboundStops) : null;
            $logger->info($batchId.' Minimum outbound stop count we found: '.($minOutboundStops ?? 'N/A'));
            $logger->info($batchId." Maximum stop count of package config (id: $packageConfig->id) is ".($packageConfig->max_stop_count ?? '0'));
            $logger->info($batchId.' Total flights after this filter: '.count($outbound_flight_max_stops ?? []));

            if ($outbound_flight_max_stops->isEmpty() && $minOutboundStops !== null) {
                $logger->warning($batchId.' No flights matched max_stop_count, falling back to least-stop flights.');

                $outbound_flight_max_stops = $outbound_flight->filter(function ($flight) use ($minOutboundStops) {
                    return $flight && $flight->stopCount === $minOutboundStops;
                });

                $logger->info($batchId.' Fallback flights (least-stop) count: '.count($outbound_flight_max_stops ?? []));
            }

            $outbound_flight = $outbound_flight_max_stops;
            $maxTransitTimeSettings = app(MaxTransitTime::class);

            if ($maxTransitTimeSettings->minutes !== 0) {
                $outbound_flight_max_wait = $outbound_flight_max_stops->filter(function ($flight) use ($maxTransitTimeSettings) {
                    if ($flight == null) {
                        return false;
                    }

                    return collect($flight->timeBetweenFlights)->every(function ($timeBetweenFlight) use ($maxTransitTimeSettings) {
                        return $timeBetweenFlight <= $maxTransitTimeSettings->minutes;
                    });
                });

                if ($outbound_flight_max_wait->isNotEmpty()) {
                    $outbound_flight = $outbound_flight_max_wait;

                    $logger->info($batchId.' Flights after filtering based on max transit time settings');
                    $logger->info($batchId.' Count: '.count($outbound_flight_max_wait ?? []));
                }
            }

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

        // if we have morning flights, find the cheapest one
        if ($outbound_flight_morning->isNotEmpty()) {
            $logger->info($batchId.' Morning Flights found');
            $logger->info($batchId.' Count after filtering based on morning flights: '.count($outbound_flight_morning ?? []));
            $outbound_flight = $outbound_flight_morning;
        }

        $outbound_flight = $outbound_flight->sortBy([
            ['stopCount', 'asc'],
            ['price', 'asc'],
        ]);

        $logger->warning('=============================================='." $batchId");
        $logger->info('Final Flights Array for batch id: '." $batchId");
        $logger->info('Final Count: '.count($outbound_flight ?? [])." $batchId");
        $logger->warning('=============================================='." $batchId");

        // if collection is empty return early and broadcast failure
        if ($outbound_flight->isEmpty()) {
            //            broadcast(new LiveSearchFailed('No flights found', $batchId));

            return;
        }

        // if morning flights are not empty get first otherwise get the first from the filtered flights
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
            'package_config_id' => $packageConfig->id,
            'all_flights' => json_encode($outbound_flight),
            'return_flight' => false,
        ]);

        $inbound_flight = Cache::get("flight:{$batchId}:{$return_date}");

        $logger->warning('=============================================='." $batchId");
        $logger->info('Now filtering for return flights for batch id:'.$batchId.'starting ...');
        $logger->warning('=============================================='." $batchId");
        $logger->info('Total for batch id: '.$batchId.' Before filter count: '.count($inbound_flight ?? []));

        $inbound_flight_direct = $inbound_flight->filter(function ($flight) {
            if ($flight == null) {
                return false;
            }

            return $flight->stopCount === 0 && $flight->stopCount_back === 0;
        });

        // if we have direct flights, keep only direct flights
        if ($inbound_flight_direct->isNotEmpty()) {
            $logger->info('Direct Flight Found'." $batchId");
            $logger->info($batchId.' Count of flights with direct flight: '.count($inbound_flight_direct ?? []));
            $inbound_flight = $inbound_flight_direct;
        } else {
            $logger->warning('No Direct Flight Found'." $batchId");
            $logger->warning($batchId.' Count of flights with 1 or more stops: '.count($inbound_flight ?? []));

            $inboundStops = [];

            $inbound_flight_max_stops = $inbound_flight->filter(function ($flight) use ($packageConfig, &$inboundStops) {
                if ($flight == null) {
                    return false;
                }

                $inboundStops[] = $flight->stopCount;

                return $flight->stopCount <= $packageConfig->max_stop_count && $flight->stopCount_back <= $packageConfig->max_stop_count;
            });

            $minInboundStops = ! empty($inboundStops) ? min($inboundStops) : null;
            $logger->info($batchId.' Minimum outbound stop count we found: '.($minInboundStops ?? 'N/A'));
            $logger->info($batchId." Maximum stop count of package config (id: $packageConfig->id) is ".$packageConfig->max_stop_count ?? 0);
            $logger->info($batchId.' Total flights after this filter: '.count($inbound_flight_max_stops ?? []));

            if ($inbound_flight_max_stops->isEmpty() && $minInboundStops !== null) {
                $logger->warning($batchId.' No flights matched max_stop_count, falling back to least-stop flights.');

                $inbound_flight_max_stops = $inbound_flight->filter(function ($flight) use ($minInboundStops) {
                    return $flight && $flight->stopCount === $minInboundStops;
                });

                $logger->info($batchId.' Fallback flights (least-stop) count: '.count($inbound_flight_max_stops ?? []));
            }

            $inbound_flight = $inbound_flight_max_stops;

            $maxTransitTimeSettings = app(MaxTransitTime::class);

            if ($maxTransitTimeSettings->minutes !== 0) {
                $inbound_flight_max_wait = $inbound_flight_max_stops->filter(function ($flight) use ($maxTransitTimeSettings) {
                    if ($flight == null) {
                        return false;
                    }

                    return collect($flight->timeBetweenFlights)->every(function ($timeBetweenFlight) use ($maxTransitTimeSettings) {
                        return $timeBetweenFlight <= $maxTransitTimeSettings->minutes;
                    });
                });

                if ($inbound_flight_max_wait->isNotEmpty()) {
                    $inbound_flight = $inbound_flight_max_wait;

                    $logger->info($batchId.' Flights after filtering based on max transit time settings');
                    $logger->info($batchId.' Count: '.count($inbound_flight_max_wait ?? []));
                }
            }
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

        // if we have morning flights, find the cheapest one
        if ($inbound_flight_evening->isNotEmpty()) {
            $logger->info($batchId.' Evening Flights found');
            $logger->info($batchId.' Count after filtering based on evening flights: '.count($inbound_flight_evening ?? []));
            $inbound_flight = $inbound_flight_evening;
        }

        //        $inbound_flight = $inbound_flight->when($destination->max_stop_count !== 0, function (Collection $collection) {
        //            return $collection->filter(function ($flight) {
        //                if ($flight == null) {
        //                    return false;
        //                }
        //
        //                return ! ($flight->stopCount <= 1 &&
        //                        $flight->stopCount > 0) || $flight->timeBetweenFlights[0] <= 360;
        //            });
        //        });

        $inbound_flight = $inbound_flight->sortBy([
            ['stopCount', 'asc'],
            ['price', 'asc'],
        ]);

        $logger->warning('=============================================='." $batchId");
        $logger->info('Final return flights array for batch id: '." $batchId");
        $logger->info('Final Count: '.count($inbound_flight)." $batchId");
        $logger->warning('=============================================='." $batchId");

        // if collection is empty return early and broadcast failure
        if ($inbound_flight->isEmpty()) {
            //            broadcast(new LiveSearchFailed('No flights found', $batchId));

            return;
        }

        $first_inbound_flight = $inbound_flight->first();

        $logger->info($first_inbound_flight);

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
            'segments' => $first_inbound_flight->segments_back,
            'package_config_id' => $packageConfig->id,
            'all_flights' => json_encode($inbound_flight),
            'return_flight' => true,
        ]);

        return [$outbound_flight_hydrated, $inbound_flight_hydrated];
    }
}
