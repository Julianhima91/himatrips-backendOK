<?php

namespace App\Actions;

use App\Models\Package;
use App\Models\PackageConfig;
use App\Settings\MaxTransitTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncFlightsAction
{
    public function handle($itineraries, $batchId, $date, $returnDate, $destination, $originId): void
    {
        $logger = Log::channel('livesearch');
        $destinationId = $destination->id;

        $outboundFlight = Package::where('batch_id', $batchId)->first()->outboundFlight;
        $inboundFlight = Package::where('batch_id', $batchId)->first()->inboundFlight;

        $apiChoice = Cache::get("flight:{$batchId}:latest");
        if ($apiChoice === 'api1') {
            $outboundFlightsApi2 = Cache::get("flight1:{$batchId}:{$date}");
            $inboundFlightsApi2 = Cache::get("flight1:{$batchId}:{$returnDate}");
        } else {
            $outboundFlightsApi2 = Cache::get("flight3:{$batchId}:{$date}");
            $inboundFlightsApi2 = Cache::get("flight3:{$batchId}:{$returnDate}");
        }

        ray('SYNCING FLIGHTS')->purple();

        $outbound_flight_direct = $outboundFlightsApi2->filter(function ($flight) {
            if ($flight == null) {
                return false;
            }

            return $flight->stopCount === 0 && $flight->stopCount_back === 0;
        });

        $packageConfig = PackageConfig::query()
            ->whereHas('destination_origin', function ($query) use ($destinationId, $originId) {
                $query->where([
                    ['destination_id', $destinationId],
                    ['origin_id', $originId],
                ]);
            })->first();

        // if we have direct flights, keep only direct flights
        if ($outbound_flight_direct->isNotEmpty()) {
            $logger->info('Direct Flight Found');
            $logger->info('Count: '.count($outbound_flight_direct ?? []));
            $outboundFlightsApi2 = $outbound_flight_direct;
        } else {
            $logger->warning('No Direct Flight Found');
            $logger->warning('Count: '.count($outboundFlightsApi2 ?? []));

            $outboundStops = [];
            $outbound_flight_max_stops = $outboundFlightsApi2->filter(function ($flight) use ($packageConfig, &$outboundStops) {
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

                $outbound_flight_max_stops = $outboundFlight->filter(function ($flight) use ($minOutboundStops) {
                    return $flight && $flight->stopCount === $minOutboundStops;
                });

                $logger->info($batchId.' Fallback flights (least-stop) count: '.count($outbound_flight_max_stops ?? []));
            }

            $outboundFlightsApi2 = $outbound_flight_max_stops;
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
                    $outboundFlightsApi2 = $outbound_flight_max_wait;

                    $logger->info('Flights after filtering based on max transit time settings');
                    $logger->info('Count: '.count($outbound_flight_max_wait ?? []));
                }
            }

        }

        $outbound_flight_morning = $outboundFlightsApi2->when($destination->prioritize_morning_flights, function (Collection $collection) use ($destination) {
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
            $logger->info('Morning Flights found');
            $logger->info('Count: '.count($outbound_flight_morning ?? []));
            $outboundFlightsApi2 = $outbound_flight_morning;
        }

        $outboundFlightsApi2 = $outboundFlightsApi2->sortBy([
            ['stopCount', 'asc'],
            ['price', 'asc'],
        ]);

        $logger->warning('==============================================');
        $logger->info('Final Flights Array');
        $logger->info('Count: '.count($outboundFlightsApi2 ?? []));
        $logger->warning('==============================================');

        // if collection is empty return early and broadcast failure
        if (! $outboundFlightsApi2->isEmpty()) {
            $before = json_decode($outboundFlight->all_flights, true) ?? [];
            $after = $before;
            $after['otherApiFlights'] = $outboundFlightsApi2;
            $outboundFlight->update([
                'all_flights' => json_encode($after),
            ]);

            $before = json_decode($inboundFlight->all_flights, true) ?? [];
            $after = $before;
            $after['otherApiFlights'] = $outboundFlightsApi2;
            $inboundFlight->update([
                'all_flights' => json_encode($after),
            ]);

            ray('Broadcasting...')->purple();
            event(new \App\Events\FlightDataUpdated(
                $batchId,
                [
                    'outbound' => $outboundFlight->fresh(),
                    'inbound' => $inboundFlight->fresh(),
                ]
            ));
        } else {
            ray('empty?')->purple();
        }
    }
}
