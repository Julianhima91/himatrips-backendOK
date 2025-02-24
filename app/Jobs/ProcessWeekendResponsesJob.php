<?php

namespace App\Jobs;

use App\Events\CheckChainWeekendJobCompletedEvent;
use App\Models\Ad;
use App\Models\AdConfig;
use App\Models\Destination;
use App\Models\FlightData;
use App\Models\HotelData;
use App\Models\HotelOffer;
use App\Models\PackageConfig;
use App\Settings\MaxTransitTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessWeekendResponsesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $batchId;

    private $request;

    private $adConfig;

    private $batchIds;

    public function __construct(string $batchId, array $request, AdConfig $adConfig, array $batchIds)
    {
        $this->batchId = $batchId;
        $this->request = $request;
        $this->adConfig = $adConfig;
        $this->batchIds = $batchIds;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $flights = Cache::get("batch:{$this->batchId}:flights");
        $hotels = Cache::get("batch:{$this->batchId}:hotels");

        //todo: move to else condition
        if (! $flights || ! $hotels) {
            Log::error("Missing data for batch {$this->batchId}");
            $this->cleanupInvalidBatch();
            event(new CheckChainWeekendJobCompletedEvent(null, $this->batchIds, $this->adConfig->id));

            return;
        }

        if ($flights && $hotels) {
            [$outbound_flight_hydrated, $inbound_flight_hydrated] = $this->handleFlights($flights, $this->request['date'], $this->batchId, $this->request['return_date'], $this->request['origin_id'], $this->request['destination_id']);
            if (is_null($outbound_flight_hydrated) && is_null($inbound_flight_hydrated)) {
                Log::warning('Both outbound and inbound flights are null. Terminating job.', [
                    'batch_id' => $this->batchId,
                ]);
                $this->cleanupInvalidBatch();
                event(new CheckChainWeekendJobCompletedEvent(null, $this->batchIds, $this->adConfig->id));

                return;
            }
            $this->handleHotelsAndPackages($hotels, $outbound_flight_hydrated, $inbound_flight_hydrated, $this->batchId, $this->request['origin_id'], $this->request['destination_id'], $this->request['rooms']);

            event(new CheckChainWeekendJobCompletedEvent($this->request['batch_id'], $this->batchIds, $this->adConfig->id));
        } else {
            Log::error("Missing data for batch {$this->batchId}");
        }
    }

    private function handleFlights($flights, $date, $batchId, $return_date, $origin_id, $destination_id): array
    {
        $outbound_flight_direct = $flights->filter(function ($flight) {
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

        if ($outbound_flight_direct->isNotEmpty()) {
            $outbound_flight = $outbound_flight_direct;
        } else {
            $outbound_flight_max_stops = $flights->filter(function ($flight) use ($packageConfig) {
                if ($flight == null) {
                    return false;
                }

                return $flight->stopCount <= $packageConfig->max_stop_count && $flight->stopCount_back <= $packageConfig->max_stop_count;
            });

            $flights = $outbound_flight_max_stops;
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
                }
            }
        }

        $destination = Destination::find($destination_id);

        $outbound_flight_morning = $flights->when(false, function (Collection $collection) use ($destination) {
            return $collection->filter(function ($flight) use ($destination) {
                if ($flight == null) {
                    return false;
                }
                if ($destination->morning_flight_start_time && $destination->morning_flight_end_time &&
                    $destination->evening_flight_start_time && $destination->evening_flight_end_time) {
                    $departure = Carbon::parse($flight->departure);
                    $departureBack = Carbon::parse($flight->departure_flight_back);

                    //                    Log::info('Destination morning flight start: ' . $destination->morning_flight_start_time);
                    //                    Log::info('Flight Departure: ' . $departure);
                    //                    Log::info('Destination morning flight end: ' . $destination->morning_flight_end_time);
                    $morningStart = Carbon::parse($flight->departure->format('Y-m-d').' '.$destination->morning_flight_start_time);
                    $morningEnd = Carbon::parse($flight->departure->format('Y-m-d').' '.$destination->morning_flight_end_time);

                    $eveningStart = Carbon::parse($flight->departure_flight_back->format('Y-m-d').' '.$destination->evening_flight_start_time);
                    $eveningEnd = Carbon::parse($flight->departure_flight_back->format('Y-m-d').' '.$destination->evening_flight_end_time);

                    $isBetween = $departure->between($morningStart, $morningEnd);
                    $isBetweenBack = $departureBack->between($eveningStart, $eveningEnd);
                    //                    Log::info('Result: ' . ($isBetween ? 'true' : 'false'));

                    return $isBetween && $isBetweenBack;
                }

                return true;
            });
        });

        if ($outbound_flight_morning->isNotEmpty()) {
            $flights = $outbound_flight_morning;
            Log::info('Early morning and late evening flights were found for destination: '.$destination->name);
        }

        $flights = $flights->sortBy([
            ['stopCount', 'asc'],
            ['price', 'asc'],
        ]);

        if ($flights->isEmpty()) {
            Log::warning("No flight for batch {$this->batchId}");
            $this->cleanupInvalidBatch();

            return [null, null];
        } else {
            $flights = $flights->reject(null);
            $first_outbound_flight = $flights[0] ?? $flights->first();

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
                'ad_config_id' => $this->adConfig->id,
                'all_flights' => json_encode($flights),
            ]);

            $inbound_flight_hydrated = FlightData::create([
                'price' => $first_outbound_flight->price,
                'departure' => $first_outbound_flight->departure_flight_back,
                'arrival' => $first_outbound_flight->arrival_flight_back,
                'airline' => $first_outbound_flight->airline_back,
                'stop_count' => $first_outbound_flight->stopCount_back,
                'origin' => $first_outbound_flight->origin_back,
                'destination' => $first_outbound_flight->destination_back,
                'adults' => $first_outbound_flight->adults,
                'children' => $first_outbound_flight->children,
                'infants' => $first_outbound_flight->infants,
                'extra_data' => json_encode($first_outbound_flight),
                'segments' => $first_outbound_flight->segments_back,
                'package_config_id' => $packageConfig->id,
                'ad_config_id' => $this->adConfig->id,
                'all_flights' => json_encode($first_outbound_flight),
            ]);

            return [$outbound_flight_hydrated, $inbound_flight_hydrated];
        }
    }

    private function handleHotelsAndPackages($hotels, mixed $outbound_flight_hydrated, mixed $inbound_flight_hydrated, $batchId, $origin_id, $destination_id, $roomObject): void
    {
        $packageConfig = PackageConfig::query()
            ->whereHas('destination_origin', function ($query) use ($origin_id, $destination_id) {
                $query->where([
                    ['destination_id', $destination_id],
                    ['origin_id', $origin_id],
                ]);
            })->first();

        foreach ($hotels as $hotel_result) {
            $hotel_data = HotelData::create([
                'hotel_id' => $hotel_result->hotel_id,
                'check_in_date' => $hotel_result->check_in_date,
                'number_of_nights' => $hotel_result->number_of_nights,
                'room_count' => $hotel_result->room_count,
                'adults' => $hotel_result->adults,
                'children' => $hotel_result->children,
                'infants' => $hotel_result->infants,
                'package_config_id' => $packageConfig->id,
                'ad_config_id' => $this->adConfig->id,
                'room_object' => json_encode($roomObject),
            ]);

            $transferPrice = 0;
            foreach ($hotel_data->hotel->transfers as $transfer) {
                $transferPrice += $transfer->adult_price * $hotel_result->adults;

                if ($hotel_result->children > 0) {
                    $transferPrice += $transfer->children_price * $hotel_result->children;
                }
            }

            $batchOffers = [];

            foreach ($hotel_result->hotel_offers as $offer) {
                $calculatedCommissionPercentage = ($packageConfig->commission_percentage / 100) * ($outbound_flight_hydrated->price + $transferPrice + $offer->price);
                $fixedCommissionRate = $packageConfig->commission_amount;
                $commission = max($fixedCommissionRate, $calculatedCommissionPercentage);

                $batchOffers[] = [
                    'hotel_data_id' => $hotel_data->id,
                    'room_basis' => $offer->room_basis,
                    'room_type' => json_encode($offer->room_type),
                    'price' => $offer->price,
                    'total_price_for_this_offer' => $outbound_flight_hydrated->price + $transferPrice + $offer->price + $commission,
                    'reservation_deadline' => $offer->reservation_deadline,
                ];
            }

            HotelOffer::insert($batchOffers);

            $first_offer = $hotel_data->offers()->orderBy('price')->first();
            $cheapestOffer = collect($hotel_data->offers)->sortBy('TotalPrice')->first();

            $hotel_data->update(['price' => $cheapestOffer->total_price_for_this_offer + $transferPrice]);
            Ad::create([
                'hotel_data_id' => $hotel_data->id,
                'outbound_flight_id' => $outbound_flight_hydrated->id,
                'inbound_flight_id' => $inbound_flight_hydrated->id,
                'commission' => $commission,
                'total_price' => $first_offer->total_price_for_this_offer,
                'batch_id' => $batchId,
                'package_config_id' => $packageConfig->id ?? null,
                'ad_config_id' => $this->adConfig->id,
                'destination_id' => $destination_id,
                'request_data' => json_encode($this->request),
                'offer_category' => $this->request['category'],
            ]);
        }
    }

    private function cleanupInvalidBatch(): void
    {
        $adConfig = $this->adConfig;
        $batchIds = Cache::get("$adConfig->id:weekend_create_csv", []);
        if (($key = array_search($this->batchId, $batchIds)) !== false) {
            unset($batchIds[$key]);
            Cache::put("$adConfig->id:weekend_create_csv", array_values($batchIds), 90);
        }

        $batchIdsGlobal = Cache::get("$adConfig->id:batch_ids", []);
        if (($key = array_search($this->batchId, $batchIdsGlobal)) !== false) {
            unset($batchIdsGlobal[$key]);
            Cache::put("$adConfig->id:batch_ids", array_values($batchIdsGlobal), 90);
        }
    }
}
