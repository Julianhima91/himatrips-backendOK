<?php

namespace App\Http\Controllers\Api;

use App\Actions\FlightsAction;
use App\Actions\HotelsAction;
use App\Actions\PackagesAction;
use App\Enums\LongFlightDestinationEnum;
use App\Events\LiveSearchCompleted;
use App\Events\LiveSearchFailed;
use App\Http\Controllers\Controller;
use App\Http\Requests\Livesearch\LivesearchRequest;
use App\Jobs\LiveSearchFlights;
use App\Jobs\LiveSearchFlightsApi2;
use App\Jobs\LiveSearchFlightsApi3;
use App\Jobs\LiveSearchHotels;
use App\Models\Ad;
use App\Models\Airport;
use App\Models\Destination;
use App\Models\DestinationOrigin;
use App\Models\DirectFlightAvailability;
use App\Models\Origin;
use App\Models\Package;
use App\Models\PackageConfig;
use Carbon\Carbon;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PackageController extends Controller
{
    public function search(Request $request)
    {
        $validated = $request->validate([
            'nights' => ['required', 'integer', 'min:1'],
            'checkin_date' => ['required', 'date_format:Y-m-d'],
            'origin_id' => ['required', 'exists:origins,id'],
            'destination_id' => ['required', 'exists:destinations,id'],
        ]);

        $destinationOrigin = DestinationOrigin::where('destination_id', $validated['destination_id'])
            ->where('origin_id', $validated['origin_id'])
            ->first();

        if (! $destinationOrigin) {
            return response()->json(['message' => 'Invalid destination or origin combination.'], 404);
        }

        $packages = Package::query()
            ->whereHas('packageConfig', fn($q) =>
            $q->where('destination_origin_id', $destinationOrigin->id)
            )
            ->whereHas('hotelData', fn($q) =>
            $q->where('check_in_date', $validated['checkin_date'])
                ->where('number_of_nights', $validated['nights'])
            )
            ->join('hotel_data', 'packages.hotel_data_id', '=', 'hotel_data.id')
            ->select([
                'packages.*',
                DB::raw('(packages.total_price - hotel_data.price) AS price_minus_hotel'),
            ])
            ->with([
                'hotelData',
                'hotelData.hotel',
                'hotelData.hotel.hotelPhotos',
                'outboundFlight',
                'inboundFlight',
                'packageConfig:id,last_processed_at',
            ])
            ->orderBy('total_price')
            ->paginate(10);

        if ($packages->isEmpty()) {
            return response()->json(['message' => 'No packages found.'], 404);
        }

        return response()->json(['data' => $packages]);
    }

    public function liveSearch(LivesearchRequest $request, FlightsAction $flights, HotelsAction $hotels, PackagesAction $packagesAction)
    {
        $logger = Log::channel('livesearch');
        $errorLogger = Log::channel('livesearch-errors');

        $totalAdults = collect($request->input('rooms'))->pluck('adults')->sum();
        $totalChildren = collect($request->input('rooms'))->pluck('children')->sum();
        $totalInfants = collect($request->input('rooms'))->pluck('infants')->sum();
        ray()->newScreen();

        $return_date = Carbon::parse($request->date)->addDays((int) $request->nights)->format('Y-m-d');

        $date = $request->date;

        $origin_airport = Airport::query()->where('origin_id', $request->origin_id)->first();
        if (! $origin_airport) {
            $errorLogger->error("Origin airport not found for origin_id: {$request->origin_id}");

            return response()->json([
                'success' => false,
                'message' => 'Origin airport not found.',
            ], 400);
        }

        $destination_airport = Airport::query()->whereHas('destinations', function ($query) use ($request) {
            $query->where('destination_id', $request->destination_id);
        })->first();
        if (! $destination_airport) {
            $errorLogger->error("Destination airport not found for destination_id: {$request->destination_id}");

            return response()->json([
                'success' => false,
                'message' => 'Destination airport not found.',
            ], 400);
        }

        $destination = Destination::where('id', $request->destination_id)->first();
        if (! $destination) {
            $errorLogger->error("Destination not found for id: {$request->destination_id}");

            return response()->json([
                'success' => false,
                'message' => 'Destination not found.',
            ], 400);
        }

        $origin = Origin::with('country')->where('id', $request->origin_id)->first();
        if (! $origin) {
            $errorLogger->error("Origin not found for id: {$request->origin_id}");

            return response()->json([
                'success' => false,
                'message' => 'Origin not found.',
            ], 400);
        }

        $destinationOrigin = DestinationOrigin::query()
            ->where([
                ['origin_id', $origin->id],
                ['destination_id', $destination->id],
            ])->first();

        if (! $destinationOrigin) {
            $errorLogger->error("Destination-Origin combination not found for origin_id: {$origin->id}, destination_id: {$destination->id}");

            return response()->json([
                'success' => false,
                'message' => 'This destination is not available from the selected origin.',
            ], 400);
        }

        $packageConfig = PackageConfig::query()
            ->where('destination_origin_id', $destinationOrigin->id)
            ->first();

        if (! $packageConfig) {
            $errorLogger->error("Package config not found for destination_origin_id: {$destinationOrigin->id}");

            return response()->json([
                'success' => false,
                'message' => 'Package configuration not found.',
            ], 400);
        }

        if ($packageConfig->is_manual) {
            return $this->manualLiveSearch($request, $flights, $hotels, $packagesAction, $destination, $origin, $destinationOrigin, $packageConfig);
        }

        try {
            $batchId = Str::orderedUuid();

            $cacheExpiration = now()->addMinutes(3);
            Cache::put("job_completed_{$batchId}", false, $cacheExpiration);
            Cache::put("hotel_job_completed_{$batchId}", false, $cacheExpiration);
            Cache::put("livesearch_cleanup_{$batchId}", true, $cacheExpiration);

            $isLongFlightDestination = LongFlightDestinationEnum::isLongFlightDestination($destination->id);

            if ($isLongFlightDestination) {
                $return_date = Carbon::parse($return_date)->addDay()->format('Y-m-d');
                // Për long flight destinations, hotel search do të bëhet pasi të marrim datën aktuale të mbërritjes së fluturimit
                $hotelStartDate = null;
            } else {
                $hotelStartDate = $date;
            }

            $jobs = [
                // new LiveSearchFlightsApi2($origin_airport, $destination_airport, $date, $return_date, $origin_airport, $destination_airport, $totalAdults, $totalChildren, $totalInfants, $batchId),
                new LiveSearchFlights($date, $return_date, $origin_airport, $destination_airport, $totalAdults, $totalChildren, $totalInfants, $batchId),
                new LiveSearchFlightsApi3($date, $return_date, $origin_airport, $destination_airport, $totalAdults, $totalChildren, $totalInfants, $batchId),
            ];

            // Për destinacione normale, dispatch-ojmë hotel search menjëherë
            // Use origin's country code for nationality (e.g., IT for Milano-Rome, GB for UK routes)
            if (!$isLongFlightDestination) {
                $countryCode = $origin->country && $origin->country->code ? $origin->country->code : 'AL';
                $nationality = strtoupper($countryCode);
                $countryName = $origin->country ? $origin->country->name : 'N/A';
                $logger->info("$batchId Using nationality: {$nationality} for origin: {$origin->name} (Country: {$countryName})");
                $jobs[] = new LiveSearchHotels($hotelStartDate, $request->nights, $request->destination_id, $totalAdults, $totalChildren, $totalInfants, $request->rooms, $batchId, $nationality);
            }

            foreach ($jobs as $job) {
                Bus::dispatch($job);
            }

            $maxWaitTime = 60; // 1 minutes timeout
            $pollInterval = 0.5; // 500ms between checks so that we dont use true statement
            $startTime = time();

            while (time() - $startTime < $maxWaitTime) {
                // Për long flight destinations, duhet të presim vetëm fluturimet
                // Për destinacione normale, duhet të presim fluturimet dhe hotelët
                $flightsCompleted = Cache::get("job_completed_{$batchId}");
                $hotelsCompleted = Cache::get("hotel_job_completed_{$batchId}");
                
                $shouldCheckHotels = !$isLongFlightDestination;
                
                if ($flightsCompleted && ($shouldCheckHotels ? $hotelsCompleted : true)) {
                    [$outbound_flight_hydrated, $inbound_flight_hydrated] = $flights->handle($date, $destination, $batchId, $return_date, $request->origin_id, $request->destination_id);

                    if (is_null($outbound_flight_hydrated) || is_null($inbound_flight_hydrated)) {
                        $this->logSearchFailure($request, $errorLogger, $packageConfig, $batchId, $date, $return_date, 'Flights null');

                        if ($outbound_flight_hydrated) {
                            $outbound_flight_hydrated->delete();
                        }
                        if ($inbound_flight_hydrated) {
                            $inbound_flight_hydrated->delete();
                        }

                        broadcast(new LiveSearchFailed('No flights found', $batchId));
                        $logger->warning("$batchId Broadcasting failed sent. FLIGHTS NULL");

                        $this->cleanupCache($batchId);

                        return response()->json([
                            'success' => false,
                            'message' => 'No flights found.',
                            'batch_id' => $batchId,
                        ], 400);
                    }

                    // Për long flight destinations, bëjmë hotel search pasi të kemi fluturimin me datën aktuale të mbërritjes
                    if ($isLongFlightDestination && !$hotelsCompleted) {
                        // Verifikojmë që fluturimet janë të vlefshme përpara se të llogarisim datat
                        if (!$outbound_flight_hydrated || !$inbound_flight_hydrated) {
                            $logger->error("$batchId Cannot calculate hotel dates - flights are null");
                            // Kjo nuk duhet të ndodhë sepse kemi kontrolluar më lart, por shtojmë si siguri
                            continue; // Skip këtë iteration dhe provo përsëri
                        }
                        
                        // Llogarit datën e check-in bazuar në datën aktuale të mbërritjes së fluturimit
                        // Për fluturime me ndalesë, duhet të marrim datën e segmentit të fundit që arrin në destinacion
                        $arrivalDate = null;
                        
                        // Nëse ka segments, marrim arrival date nga segmenti i fundit (që arrin në destinacion)
                        // Segments tani është automatikisht array për shkak të cast-imit në FlightData model
                        $segments = $outbound_flight_hydrated->segments;
                        
                        if (is_array($segments) && count($segments) > 0) {
                            // Segmenti i fundit është ai që arrin në destinacion
                            $lastSegment = end($segments);
                            reset($segments); // Reset pointer
                            
                            // Kontrollojmë të gjitha formatet e mundshme të arrival
                            // API 1 dhe API 3 përdorin 'arrival', por kontrollojmë edhe variante të tjera
                            $arrivalTime = $lastSegment['arrival'] 
                                ?? $lastSegment['arrival_at'] 
                                ?? $lastSegment['arrivalDateTime'] 
                                ?? $lastSegment['arrival_datetime']
                                ?? null;
                            
                            if ($arrivalTime) {
                                try {
                                    $arrivalDate = Carbon::parse($arrivalTime);
                                    $logger->info("$batchId Using last segment arrival date: {$arrivalTime} (Flight has " . count($segments) . " segments, last segment arrives at: {$arrivalDate->format('Y-m-d H:i')})");
                                } catch (\Exception $e) {
                                    $logger->warning("$batchId Failed to parse last segment arrival: {$arrivalTime} - {$e->getMessage()}");
                                }
                            } else {
                                $logger->warning("$batchId Last segment does not have arrival time. Segment structure: " . json_encode(array_keys($lastSegment)));
                            }
                        }
                        
                        // Nëse nuk kemi segments ose nuk mund t'i marrim, përdorim arrival total
                        if (!$arrivalDate) {
                            // Verifikojmë që arrival nuk është null
                            if (!$outbound_flight_hydrated->arrival) {
                                $logger->error("$batchId Outbound flight arrival is null. Using request date + 1 day as fallback.");
                                $arrivalDate = Carbon::parse($date)->addDay();
                            } else {
                                try {
                                    $arrivalDate = Carbon::parse($outbound_flight_hydrated->arrival);
                                    $logger->info("$batchId Using total flight arrival date: {$outbound_flight_hydrated->arrival} (segments not available or parsing failed)");
                                } catch (\Exception $e) {
                                    // Nëse edhe arrival total dështon, përdorim datën nga request si fallback dhe shtojmë 1 ditë
                                    $logger->error("$batchId Failed to parse outbound flight arrival date: {$outbound_flight_hydrated->arrival} - {$e->getMessage()}. Using request date + 1 day as fallback.");
                                    $arrivalDate = Carbon::parse($date)->addDay();
                                }
                            }
                        }
                        
                        // Verifikojmë që arrivalDate është e vlefshme
                        if (!$arrivalDate || !($arrivalDate instanceof Carbon)) {
                            $logger->error("$batchId Invalid arrival date after all attempts. Using request date + 1 day as final fallback.");
                            $arrivalDate = Carbon::parse($date)->addDay();
                        }
                        
                        // Verifikojmë që arrival date nuk është në të shkuarën (relative to today)
                        // Por lejojmë që të jetë në të kaluarën nëse është vetëm pak (p.sh. për teste)
                        $hotelCheckInDate = $arrivalDate->format('Y-m-d');
                        
                        // Llogarit check-out date bazuar në datën e nisjes së fluturimit kthimit
                        // Për fluturime me ndalesë, duhet të marrim departure date nga segmenti i parë i inbound flight
                        $checkOutDate = null;
                        
                        // Nëse ka segments për inbound flight, marrim departure date nga segmenti i parë
                        $inboundSegments = $inbound_flight_hydrated->segments;
                        
                        if (is_array($inboundSegments) && count($inboundSegments) > 0) {
                            // Segmenti i parë është ai që niset nga destinacioni (hotel check-out para kësaj date)
                            $firstSegment = reset($inboundSegments);
                            
                            // Kontrollojmë të gjitha formatet e mundshme të departure
                            $departureTime = $firstSegment['departure'] 
                                ?? $firstSegment['departure_at'] 
                                ?? $firstSegment['departureDateTime'] 
                                ?? $firstSegment['departure_datetime']
                                ?? null;
                            
                            if ($departureTime) {
                                try {
                                    $checkOutDate = Carbon::parse($departureTime);
                                    $logger->info("$batchId Using first inbound segment departure date: {$departureTime} (Inbound flight has " . count($inboundSegments) . " segments, first segment departs at: {$checkOutDate->format('Y-m-d H:i')})");
                                } catch (\Exception $e) {
                                    $logger->warning("$batchId Failed to parse first inbound segment departure: {$departureTime} - {$e->getMessage()}");
                                }
                            }
                        }
                        
                        // Nëse nuk kemi segments ose nuk mund t'i marrim, përdorim departure total
                        if (!$checkOutDate) {
                            // Verifikojmë që departure nuk është null
                            if (!$inbound_flight_hydrated->departure) {
                                $logger->error("$batchId Inbound flight departure is null. Using check-in + request nights as fallback.");
                                $checkOutDate = $arrivalDate->copy()->addDays($request->nights);
                            } else {
                                try {
                                    $checkOutDate = Carbon::parse($inbound_flight_hydrated->departure);
                                    $logger->info("$batchId Using total inbound flight departure date: {$inbound_flight_hydrated->departure} (segments not available or parsing failed)");
                                } catch (\Exception $e) {
                                    // Nëse edhe departure total dështon, llogarisim bazuar në check-in date + nights
                                    $logger->error("$batchId Failed to parse inbound flight departure date: {$inbound_flight_hydrated->departure} - {$e->getMessage()}. Using check-in + request nights as fallback.");
                                    $checkOutDate = $arrivalDate->copy()->addDays($request->nights);
                                }
                            }
                        }
                        
                        // Verifikojmë që checkOutDate është e vlefshme
                        if (!$checkOutDate || !($checkOutDate instanceof Carbon)) {
                            $logger->error("$batchId Invalid check-out date after all attempts. Using check-in + request nights as final fallback.");
                            $checkOutDate = $arrivalDate->copy()->addDays($request->nights);
                        }
                        
                        // Verifikojmë që check-out date është pas check-in date
                        if ($checkOutDate <= $arrivalDate) {
                            $logger->warning("$batchId Check-out date ({$checkOutDate->format('Y-m-d')}) is not after check-in date ({$arrivalDate->format('Y-m-d')}). Using check-in + request nights.");
                            $checkOutDate = $arrivalDate->copy()->addDays($request->nights);
                        }
                        
                        // Llogarit numrin e netëve bazuar në check-in dhe check-out dates
                        // Check-out date zakonisht është ditën e nisjes së fluturimit, por hotel check-out është më herët në atë ditë
                        // Prandaj, llogarisim diferencën në ditë midis check-in dhe check-out
                        // Përdorim diff() dhe days për të siguruar rezultat të saktë
                        $dateDiff = $arrivalDate->diff($checkOutDate);
                        $calculatedNights = $dateDiff->days;
                        
                        // Nëse check-out është para check-in (jo normal, por e mundur), përdorim abs
                        if ($checkOutDate < $arrivalDate) {
                            $logger->warning("$batchId Check-out date ({$checkOutDate->format('Y-m-d')}) is before check-in date ({$arrivalDate->format('Y-m-d')}). This shouldn't happen!");
                            $calculatedNights = abs($calculatedNights);
                        }
                        
                        // Verifikojmë që numri i netëve është i vlefshëm (duhet të jetë pozitiv dhe të paktën 1)
                        if ($calculatedNights <= 0) {
                            $logger->warning("$batchId Calculated nights is {$calculatedNights}, using request nights: {$request->nights}");
                            $calculatedNights = $request->nights; // Fallback në nights nga request
                        }
                        
                        // Verifikojmë që numri i llogaritur i netëve nuk është shumë i ndryshëm nga ai i kërkuar
                        // Nëse diferenca është më shumë se 2 netë, diçka është gabim
                        if (abs($calculatedNights - $request->nights) > 2) {
                            $logger->warning("$batchId Calculated nights ({$calculatedNights}) differs significantly from request nights ({$request->nights}). Using calculated nights but logging warning.");
                        }
                        
                        $logger->info("$batchId Long flight destination - Check-in: {$hotelCheckInDate}, Check-out: {$checkOutDate->format('Y-m-d')}, Calculated nights: {$calculatedNights} (Request nights: {$request->nights})");
                        
                        // Dispatch hotel search me datën dhe numrin e netëve të saktë
                        // Use origin's country code for nationality (e.g., IT for Milano-Rome, GB for UK routes)
                        $countryCode = $origin->country && $origin->country->code ? $origin->country->code : 'AL';
                        $nationality = strtoupper($countryCode);
                        try {
                            LiveSearchHotels::dispatch($hotelCheckInDate, $calculatedNights, $request->destination_id, $totalAdults, $totalChildren, $totalInfants, $request->rooms, $batchId, $nationality);
                            $logger->info("$batchId Dispatched hotel search for long flight destination with check-in: {$hotelCheckInDate}, nights: {$calculatedNights}");
                        } catch (\Exception $e) {
                            $logger->error("$batchId Failed to dispatch hotel search: {$e->getMessage()}");
                            // Nëse dispatch dështon, përdorim datën origjinale si fallback
                            $hotelCheckInDate = Carbon::parse($date)->addDay()->format('Y-m-d');
                            $calculatedNights = $request->nights;
                            LiveSearchHotels::dispatch($hotelCheckInDate, $calculatedNights, $request->destination_id, $totalAdults, $totalChildren, $totalInfants, $request->rooms, $batchId, $nationality);
                            $logger->info("$batchId Retried hotel search with fallback dates");
                        }
                        
                        // Presim që hotel search të mbarojë
                        $hotelSearchStartTime = time();
                        $hotelSearchMaxWait = 45; // 45 sekonda për hotel search
                        $hotelSearchTimedOut = false;
                        
                        while (time() - $hotelSearchStartTime < $hotelSearchMaxWait) {
                            if (Cache::get("hotel_job_completed_{$batchId}")) {
                                $logger->info("$batchId Hotel search completed successfully");
                                break;
                            }
                            usleep($pollInterval * 1000000);
                        }
                        
                        if (!Cache::get("hotel_job_completed_{$batchId}")) {
                            $hotelSearchTimedOut = true;
                            $logger->warning("$batchId Hotel search timeout for long flight destination after {$hotelSearchMaxWait} seconds");
                            // Vazhdojmë përpara sepse mund të kemi pak rezultate në cache
                        }
                        
                        // Nëse hotel search dështoi kompletisht, nuk ka rezultate
                        if ($hotelSearchTimedOut && empty(Cache::get("hotels:{$batchId}"))) {
                            $logger->error("$batchId Hotel search failed completely - no results in cache");
                            // Në këtë rast, do të kthehemi më poshtë me hotels null dhe do të broadcast-ojmë failure
                        }
                    }

                    $package_ids = $hotels->handle($destination, $outbound_flight_hydrated, $inbound_flight_hydrated, $batchId, $request->origin_id, $request->destination_id, $request->input('rooms'));

                    if ($package_ids === ['success' => false] || empty($package_ids)) {
                        $this->logSearchFailure($request, $errorLogger, $packageConfig, $batchId, $date, $return_date, 'Hotels null');

                        $outbound_flight_hydrated->delete();
                        $inbound_flight_hydrated->delete();

                        broadcast(new LiveSearchFailed('No hotels found', $batchId));
                        $logger->warning("$batchId Broadcasting failed sent. HOTELS NULL");

                        $this->cleanupCache($batchId);

                        return response()->json([
                            'success' => false,
                            'message' => 'No hotels found for the selected destination and dates.',
                            'batch_id' => $batchId,
                        ], 400);
                    }

                    $firstBoardOption = $destination->board_options ?? null;
                    [$packages, $minTotalPrice, $maxTotalPrice, $packageConfigId] = $packagesAction->handle($package_ids, $firstBoardOption);

                    broadcast(new LiveSearchCompleted($packages, $batchId, $minTotalPrice, $maxTotalPrice, $packageConfigId, $firstBoardOption));
                    $this->cleanupCache($batchId);

                    return response()->json([
                        'success' => true,
                        'message' => 'Live search started',
                        'data' => ['batch_id' => $batchId],
                    ], 200);
                }

                usleep($pollInterval * 1000000); // microseconds
            }

            // Nëse kemi arritur timeout dhe për long flight destinations hotel search nuk ka filluar akoma
            // Por vetëm nëse fluturimet kanë mbaruar
            $flightsCompletedAtTimeout = Cache::get("job_completed_{$batchId}");
            if ($isLongFlightDestination && $flightsCompletedAtTimeout && !Cache::get("hotel_job_completed_{$batchId}") && empty(Cache::get("hotels:{$batchId}"))) {
                $logger->error("Live search timeout for batch: $batchId (Long flight destination - flights completed but hotel search not started)");
                
                // Mund të provojmë të bëjmë hotel search me fallback dates
                // Por vetëm nëse fluturimet kanë mbaruar me sukses
                try {
                    // Përpiqemi të marrim fluturimet nga cache për të llogaritur datat
                    $cachedFlights = Cache::get("batch:{$batchId}:flights");
                    if ($cachedFlights) {
                        // Mund të provojmë të marrim fluturimin e parë dhe të llogarisim datat
                        $logger->info("$batchId Attempting fallback hotel search with cached flights");
                        // Në këtë rast, do të përdorim datën e request + 1 ditë si fallback
                    }
                    
                    $fallbackCheckInDate = Carbon::parse($date)->addDay()->format('Y-m-d');
                    $logger->info("$batchId Attempting fallback hotel search with date: {$fallbackCheckInDate}");
                    // Use origin's country code for nationality (e.g., IT for Milano-Rome, GB for UK routes)
                    $countryCode = $origin->country && $origin->country->code ? $origin->country->code : 'AL';
                    $nationality = strtoupper($countryCode);
                    LiveSearchHotels::dispatch($fallbackCheckInDate, $request->nights, $request->destination_id, $totalAdults, $totalChildren, $totalInfants, $request->rooms, $batchId, $nationality);
                    
                    // Presim pak më shumë për hotel search fallback
                    $fallbackWaitTime = 30;
                    $fallbackStartTime = time();
                    while (time() - $fallbackStartTime < $fallbackWaitTime) {
                        if (Cache::get("hotel_job_completed_{$batchId}")) {
                            $logger->info("$batchId Fallback hotel search completed successfully");
                            break;
                        }
                        usleep($pollInterval * 1000000);
                    }
                    
                    if (!Cache::get("hotel_job_completed_{$batchId}")) {
                        $logger->warning("$batchId Fallback hotel search also timed out");
                    }
                } catch (\Exception $e) {
                    $logger->error("$batchId Fallback hotel search failed: {$e->getMessage()}");
                }
            } elseif ($isLongFlightDestination && !$flightsCompletedAtTimeout) {
                $logger->error("Live search timeout for batch: $batchId (Long flight destination - flights did not complete in time)");
            }
            
            $this->cleanupCache($batchId);
            $logger->error("Live search timeout for batch: $batchId");
            broadcast(new LiveSearchFailed('Search timeout', $batchId));

            return response()->json([
                'success' => false,
                'message' => 'Search timeout. Please try again.',
                'batch_id' => $batchId,
            ], 408);

        } catch (Throwable $e) {
            $logger->error('Live search error: '.$e->getMessage());
            $this->cleanupCache($batchId ?? null);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred during the search.',
            ], 500);
        }
    }

    /**
     * Helper method to log search failures with consistent format
     */
    private function logSearchFailure($request, $errorLogger, $packageConfig, $batchId, $date, $return_date, $reason)
    {
        $endpoint = $request->url();
        $method = strtoupper($request->method());

        $importantHeaders = ['accept', 'authorization'];
        $headers = collect($request->headers->all())
            ->filter(fn ($values, $key) => in_array(strtolower($key), $importantHeaders))
            ->map(fn ($values, $key) => "-H \"$key: ".implode('; ', $values).'"')
            ->implode(' ');

        $body = json_encode($request->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $curlCommand = "curl -X {$method} '{$endpoint}' {$headers} -d '{$body}'";

        $errorLogger->info("Reproduce with cURL:\n{$curlCommand}");
        $errorLogger->info("Package Config ID: $packageConfig->id");
        $errorLogger->info('Package Config Origin: '.$packageConfig->destination_origin->origin->name.' | Origin ID: '.$packageConfig->destination_origin->origin_id);
        $errorLogger->info('Package Config Destination: '.$packageConfig->destination_origin->destination->name.' | Destination ID: '.$packageConfig->destination_origin->destination_id);
        $errorLogger->info('-------------------------------------------------------------------------');
        $errorLogger->info('Request Data:');
        $errorLogger->info("Batch ID: $batchId");
        $errorLogger->info("Date Start: $date");
        $errorLogger->info("Date End: $return_date");
        $errorLogger->info('Rooms: '.json_encode($request->rooms, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $errorLogger->info('-------------------------------------------------------------------------');
        $errorLogger->info("Reason: $reason");
        $errorLogger->info("Check livesearch.log for detailed information for batch id: $batchId");
        $errorLogger->info('END======================================================================');
    }

    /**
     * Helper method to clean up cache entries
     */
    private function cleanupCache($batchId)
    {
        if (! $batchId) {
            return;
        }

        Cache::forget("job_completed_{$batchId}");
        Cache::forget("hotel_job_completed_{$batchId}");
        Cache::forget("livesearch_cleanup_{$batchId}");
    }

    public function show(Package $package)
    {
        // we need to return the whole package here
        // this will include the hotel data, hotel photos, flight data

        $package->load(['hotelData', 'hotelData.hotel', 'hotelData.hotel.hotelPhotos', 'outboundFlight', 'inboundFlight', 'hotelData.offers']);

        return response()->json([
            'data' => $package,
        ], 200);
    }

    public function hasAvailableDates(Request $request)
    {
        $destination_origin =
            DestinationOrigin::where([
                ['destination_id', $request->destination_id],
                ['origin_id', $request->origin_id],
            ])->first();

        if (! $destination_origin) {
            return response()->json([
                'data' => 'There is no destination origin',
            ], 200);
        }

        $directFlightDates = DirectFlightAvailability::query()
            ->where([
                ['destination_origin_id', $destination_origin->id],
                ['is_return_flight', 0],
            ])
            ->pluck('date')->toArray();

        if ($directFlightDates) {
            return response()->json([
                'data' => [
                    'dates' => $directFlightDates,
                ],
            ], 200);
        } else {
            return response()->json([
                'data' => 'There are no available dates',
            ], 200);
        }
    }

    public function hasAvailableReturn(Request $request)
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'destination_id' => ['required', 'exists:destinations,id'],
            'origin_id' => ['required', 'exists:origins,id'],
        ]);

        $destinationOrigin = DestinationOrigin::where([
            ['destination_id', $validated['destination_id']],
            ['origin_id', $validated['origin_id']],
        ])->first();

        if (! $destinationOrigin) {
            return response()->json([
                'data' => 'No matching destination-origin found.',
            ], 404);
        }

        $packageConfig = PackageConfig::where('destination_origin_id', $destinationOrigin->id)->first();

        if (! $packageConfig || ! $packageConfig->destination_origin?->destination) {
            return response()->json([
                'data' => 'No configuration found for this route.',
            ], 404);
        }

        $minNightsStay = $packageConfig->destination_origin->destination->min_nights_stay ?? 0;

        $minReturnDate = Carbon::parse($validated['start_date'])->addDays($minNightsStay);

        $availableReturnDates = DirectFlightAvailability::query()
            ->where('destination_origin_id', $destinationOrigin->id)
            ->where('is_return_flight', true)
            ->whereDate('date', '>=', $minReturnDate)
            ->orderBy('date')
            ->limit(15)
            ->pluck('date')
            ->toArray();

        if (empty($availableReturnDates)) {
            return response()->json([
                'data' => 'There are no available return flights.',
            ], 200);
        }

        return response()->json([
            'data' => [
                'dates' => $availableReturnDates,
            ],
        ]);
    }

    public function getAvailableDates(Request $request)
    {
        $destination_origin =
            DestinationOrigin::where([
                ['destination_id', $request->destination_id],
                ['origin_id', $request->origin_id],
            ])->first();

        $directFlightDates = DirectFlightAvailability::where('destination_origin_id', $destination_origin->id)
            ->pluck('date')->toArray();

        if ($directFlightDates) {
            return response()->json([
                'data' => $directFlightDates,
            ], 200);
        }

        // lets modify this so we automatically get the first month and year
        // if the user has not selected a month and year
        if (! $request->month || ! $request->year) {
            $first_available_date = Package::whereHas('packageConfig', function ($query) use ($destination_origin) {
                $query->where('destination_origin_id', $destination_origin->id);
            })
                ->whereHas('hotelData', function ($query) {
                    $query->where('check_in_date', '>=', Carbon::now()->format('Y-m-d'));
                })
                ->with(['hotelData'])
                ->get()
                ->unique('hotelData.check_in_date')
                ->pluck('hotelData.check_in_date')
                ->first();

            $request->merge([
                'month' => Carbon::parse($first_available_date)->format('m'),
                'year' => Carbon::parse($first_available_date)->format('Y'),
            ]);
        }

        $dates = Package::whereHas('packageConfig', function ($query) use ($destination_origin) {
            $query->where('destination_origin_id', $destination_origin->id);
        })
            ->whereHas('hotelData', function ($query) use ($request) {
                $query->whereMonth('check_in_date', $request->month)
                    ->whereYear('check_in_date', $request->year)
                    ->where('check_in_date', '>=', Carbon::now()->format('Y-m-d'));
            })
            ->with(['hotelData'])
            ->get()
            ->unique('hotelData.check_in_date')
            ->pluck('hotelData.check_in_date');

        return response()->json([
            'data' => $dates,
        ], 200);

    }

    public function getAvailableNights(Request $request)
    {
        $destination_origin = DestinationOrigin::where('destination_id', $request->destination_id)
            ->where('origin_id', $request->origin_id)
            ->first();

        $nights = Package::whereHas('packageConfig', function ($query) use ($destination_origin) {
            $query->where('destination_origin_id', $destination_origin->id);
        })
            ->whereHas('hotelData', function ($query) use ($request) {
                $query->where('check_in_date', $request->checkin_date);
            })
            ->with(['hotelData'])
            ->get()
            ->unique('hotelData.number_of_nights')
            ->pluck('hotelData.number_of_nights');

        return response()->json([
            'data' => $nights,
        ], 200);
    }

    public function paginateLiveSearch(Request $request, FlightsAction $flights, HotelsAction $hotels, PackagesAction $packagesAction)
    {
        $packages = Package::withTrashed()->where('batch_id', $request->batch_id)
            ->when($request->price_range, function ($query) use ($request) {
                $query->whereBetween('total_price', [$request->price_range[0], $request->price_range[1]]);
            })
            ->when($request->review_scores, function ($query) use ($request) {
                $query->whereHas('hotelData.hotel', function ($query) use ($request) {
                    $query->where('review_score', '>=', $request->review_scores);
                });
            })
            ->when($request->stars, function ($query) use ($request) {
                $query->whereHas('hotelData.hotel', function ($query) use ($request) {
                    $query->whereIn('stars', $request->stars);
                });
            })
            ->when($request->room_basis, function ($query) use ($request) {
                $query->whereHas('hotelData.offers', function ($query) use ($request) {
                    $query->whereIn('room_basis', $request->room_basis);
                });
            })
            ->with([
                'hotelData',
                'hotelData.hotel',
                'hotelData.hotel.transfers',
                'hotelData.hotel.hotelPhotos',
                'outboundFlight',
                'inboundFlight',
                'hotelData.offers' => function ($query) use ($request) {
                    $query->when($request->room_basis, function ($query) use ($request) {
                        $query->whereIn('room_basis', $request->room_basis);
                    })
                        ->orderBy('price', 'asc');
                },
            ])
            ->get()
            ->sortBy(function (Package $package) {
                return $package->hotelData->offers->first()->total_price_for_this_offer;
            })
            ->values()
            ->all();

        if (! empty($packages) && isset($packages[0]->deleted_at)) {
            $livesearchRequest = new LivesearchRequest;

            $roomCount = $packages[0]->packageConfig->hotelData[0]->room_count;
            $adults = $packages[0]->packageConfig->hotelData[0]->adults;
            $children = $packages[0]->packageConfig->hotelData[0]->children;
            $infants = $packages[0]->packageConfig->hotelData[0]->infants;

            $totalPeople = $adults + $children + $infants;
            $peoplePerRoom = intdiv($totalPeople, $roomCount);
            $extraPeople = $totalPeople % $roomCount;

            $rooms = [];
            for ($i = 0; $i < $roomCount; $i++) {
                $assignedPeople = $peoplePerRoom;

                if ($extraPeople > 0) {
                    $assignedPeople++;
                    $extraPeople--;
                }

                $assignedAdults = min($assignedPeople, $adults);
                $assignedPeople -= $assignedAdults;
                $adults -= $assignedAdults;

                $assignedChildren = min($assignedPeople, $children);
                $assignedPeople -= $assignedChildren;
                $children -= $assignedChildren;

                $assignedInfants = $assignedPeople;
                $infants -= $assignedInfants;

                $rooms[] = [
                    'adults' => $assignedAdults,
                    'children' => $assignedChildren,
                    'infants' => $assignedInfants,
                ];
            }

            if (! $packages[0]->outboundFlight) {
                Log::error('No outbound flight found for package: '.$packages[0]->id.' batch id: '.$request->batch_id);
            }

            $livesearchRequest->merge([
                'nights' => $packages[0]->hotelData->number_of_nights,
                'date' => $packages[0]->outboundFlight->departure->format('Y-m-d'),
                'origin_id' => $packages[0]->packageConfig->destination_origin->origin_id,
                'destination_id' => $packages[0]->packageConfig->destination_origin->destination_id,
                'rooms' => $rooms,
            ]);

            return $this->liveSearch($livesearchRequest, $flights, $hotels, $packagesAction);
        }
        $packages = collect($packages);

        $page = $request->page ?? 1;
        $perPage = $request->per_page ?? 10;

        $paginatedData = $packages->forPage($page, $perPage)->values();

        return response()->json([
            'data' => [
                'content_id' => isset($packages[0]->package_config_id) ? $packages[0]->package_config_id : false,
                'data' => $paginatedData,
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $packages->count(),
            ],
        ]);
    }

    public function getFilterData(Request $request)
    {
        $request->validate([
            'batch_id' => 'required',
        ]);

        $batch = $request->batch_id;

        // Step 1: Get unique package IDs for the given batch_id
        $uniquePackageIds = Package::where('batch_id', $batch)
            ->when($request->price_range, function ($query) use ($request) {
                $query->whereBetween('total_price', $request->price_range);
            })
            ->when($request->review_scores, function ($query) use ($request) {
                $query->whereHas('hotelData.hotel', function ($query) use ($request) {
                    $query->where('review_score', '>=', $request->review_scores);
                });
            })
            ->when($request->stars, function ($query) use ($request) {
                $query->whereHas('hotelData.hotel', function ($query) use ($request) {
                    $query->whereIn('stars', $request->stars);
                });
            })
            ->when($request->room_basis, function ($query) use ($request) {
                $query->whereHas('hotelData.offers', function ($query) use ($request) {
                    $query->whereIn('room_basis', $request->room_basis);
                });
            })
            ->distinct()
            ->pluck('id');

        // Step 2: Join the necessary tables and group by stars with min/max prices
        $packagesCountByStars = DB::table('packages')
            ->join('hotel_data', 'packages.hotel_data_id', '=', 'hotel_data.id')
            ->join('hotels', 'hotel_data.hotel_id', '=', 'hotels.id')
            ->join('hotel_offers', 'hotel_data.id', '=', 'hotel_offers.hotel_data_id')
            ->whereIn('packages.id', $uniquePackageIds)
            ->select(
                'hotels.stars',
                DB::raw('count(distinct packages.id) as package_count'),
                DB::raw('MIN(hotel_offers.total_price_for_this_offer) as min_price'),
                DB::raw('MAX(hotel_offers.total_price_for_this_offer) as max_price')
            )
            ->groupBy('hotels.stars')
            ->get();

        $packagesCountByStars = $packagesCountByStars
            ->mapWithKeys(function ($item) {
                return [
                    $item->stars => [
                        'package_count' => $item->package_count,
                        'min_price' => $item->min_price,
                        'max_price' => $item->max_price,
                    ],
                ];
            })
            ->toArray();

        // Step 3: Join the necessary tables and group by review scores with min/max prices
        $packagesCountByReviewScores = DB::table('packages')
            ->join('hotel_data', 'packages.hotel_data_id', '=', 'hotel_data.id')
            ->join('hotels', 'hotel_data.hotel_id', '=', 'hotels.id')
            ->join('hotel_offers', 'hotel_data.id', '=', 'hotel_offers.hotel_data_id')
            ->whereIn('packages.id', $uniquePackageIds)
            ->select(
                'hotels.review_score',
                DB::raw('count(distinct packages.id) as package_count'),
                DB::raw('MIN(hotel_offers.total_price_for_this_offer) as min_price'),
                DB::raw('MAX(hotel_offers.total_price_for_this_offer) as max_price')
            )
            ->groupBy('hotels.review_score')
            ->get();

        $packagesCountByReviewScores = $packagesCountByReviewScores
            ->mapWithKeys(function ($item) {
                return [
                    $item->review_score => [
                        'package_count' => $item->package_count,
                        'min_price' => $item->min_price,
                        'max_price' => $item->max_price,
                    ],
                ];
            })
            ->toArray();

        // Step 4: Join the necessary tables and group by room_basis with min/max prices
        $packagesCountByRoomBasis = DB::table('packages')
            ->join('hotel_data', 'packages.hotel_data_id', '=', 'hotel_data.id')
            ->join('hotels', 'hotel_data.hotel_id', '=', 'hotels.id')
            ->join('hotel_offers', 'hotel_data.id', '=', 'hotel_offers.hotel_data_id')
            ->whereIn('packages.id', $uniquePackageIds)
            ->select(
                'hotel_offers.room_basis',
                DB::raw('count(distinct packages.id) as package_count'),
                DB::raw('MIN(hotel_offers.total_price_for_this_offer) as min_price'),
                DB::raw('MAX(hotel_offers.total_price_for_this_offer) as max_price')
            )
            ->groupBy('hotel_offers.room_basis')
            ->get();

        $packagesCountByRoomBasis = $packagesCountByRoomBasis
            ->mapWithKeys(function ($item) {
                return [
                    $item->room_basis => [
                        'package_count' => $item->package_count,
                        'min_price' => $item->min_price,
                        'max_price' => $item->max_price,
                    ],
                ];
            })
            ->toArray();

        // Step 5: Get min/max prices for all packages
        $minMaxPrices = DB::table('packages')
            ->join('hotel_data', 'packages.hotel_data_id', '=', 'hotel_data.id')
            ->join('hotels', 'hotel_data.hotel_id', '=', 'hotels.id')
            ->join('hotel_offers', 'hotel_data.id', '=', 'hotel_offers.hotel_data_id')
            ->whereIn('packages.id', $uniquePackageIds)
            ->select(
                DB::raw('MIN(hotel_offers.total_price_for_this_offer) as min_price'),
                DB::raw('MAX(hotel_offers.total_price_for_this_offer) as max_price')
            )
            ->first();

        return response()->json([
            'data' => [
                'packagesCountByStars' => $packagesCountByStars,
                'packagesCountByReviewScores' => $packagesCountByReviewScores,
                'packagesCountByRoomBasis' => $packagesCountByRoomBasis,
                'minMaxPrices' => $minMaxPrices,
            ],
        ], 200);
    }

    public function offers(Request $request)
    {
        $originId = (int) ($request->origin_id ?? 1);

        $cheapestPackages = Cache::remember($originId, 180, function () use ($originId) {
            $packages = collect();

            Destination::query()
                ->select(['id', 'name', 'description', 'city', 'country', 'created_at', 'updated_at', 'show_in_homepage'])
                ->with([
                    'destinationPhotos:id,destination_id,file_path',
                    'destinationOrigin.packages.outboundFlight:id,package_config_id,departure,adults,children,infants',
                    'destinationOrigin.packages.inboundFlight:id,package_config_id,departure',
                    'destinationOrigin.packages.packageConfig:id,destination_origin_id',
                ])
                ->whereHas('destinationOrigin.packages')
                ->chunk(100, function ($destinations) use (&$packages, $originId) {
                    foreach ($destinations as $destination) {
                        $filteredPackages = $destination->destinationOrigin
                            ->filter(fn ($origin) => $origin->origin_id === $originId)
                            ->flatMap(fn ($origin) => $origin->packages)
                            ->filter(fn ($package) => $this->isValidPackage($package));

                        $cheapestPackage = $filteredPackages->sortBy('total_price')->first();

                        if ($cheapestPackage) {
                            $packages->push($this->formatPackageData($destination, $cheapestPackage));
                        }
                    }
                });

            return $packages->values();
        });

        return response()->json([
            'data' => $cheapestPackages,
        ], 200);
    }

    private function isValidPackage($package)
    {
        $outboundFlight = $package->outboundFlight;
        $inboundFlight = $package->inboundFlight;
        $today = new DateTime('today');

        if (! $outboundFlight || ! $inboundFlight) {
            return false;
        }

        $outboundDate = new DateTime($outboundFlight->departure);
        $inboundDate = new DateTime($inboundFlight->departure);
        $nightsStay = $inboundDate->diff($outboundDate)->days;

        return $nightsStay >= 2
            && $outboundFlight->adults == 2
            && $outboundFlight->children == 0
            && $outboundFlight->infants == 0
            && $outboundDate > $today;
    }

    private function formatPackageData($destination, $cheapestPackage)
    {
        $outboundDate = new DateTime($cheapestPackage->outboundFlight->departure);
        $inboundDate = new DateTime($cheapestPackage->inboundFlight->departure);
        $nights = $inboundDate->diff($outboundDate)->days;

        return array_merge(
            $destination->only(['id', 'name', 'description', 'city', 'country', 'created_at', 'updated_at', 'show_in_homepage']),
            ['price' => $cheapestPackage->total_price],
            ['batch_id' => $cheapestPackage->batch_id],
            ['adults' => $cheapestPackage->outboundFlight->adults],
            ['children' => $cheapestPackage->outboundFlight->children],
            ['infants' => $cheapestPackage->outboundFlight->infants],
            ['nights' => $nights],
            ['checkin_date' => $outboundDate->format('Y-m-d')],
            ['photos' => $destination->destinationPhotos],
            ['destination_origin' => $cheapestPackage->packageConfig->destination_origin],
        );
    }

    public function getAllFlights($batchId, Request $request)
    {
        $package = Package::where('batch_id', $batchId)->first();

        if (! $package) {
            return response()->json(['message' => 'Incorrect batch id.'], 400);
        }
        $currentPrice = $package->outboundFlight->price;

        $flights = json_decode($package->outboundFlight->all_flights, true);

        if (! $flights) {
            return response()->json(['message' => 'No flights available for this package.'], 404);
        }

        if (isset($flights['otherApiFlights'])) {
            $flights = array_merge($flights, $flights['otherApiFlights']);
            unset($flights['otherApiFlights']);
        }

        $stops = $request->input('stops');
        $allStops = [];

        $flights = array_filter($flights);
        $filteredFlights = [];
        foreach ($flights as $originalIndex => $flight) {
            if ($flight) {
                $allStops[] = $flight['stopCount'];
                $allStops[] = $flight['stopCount_back'];
                $priceDifference = $flight['price'] - $currentPrice;

                if (is_null($stops) || ($stops == 0 && $flight['stopCount'] == $stops && $flight['stopCount_back'] == $stops)) {
                    $filteredFlights[] = [
                        'filtered_index' => count($filteredFlights),
                        'original_index' => $originalIndex,
                        'price_difference' => $priceDifference,
                        'flight' => $flight,
                    ];
                } elseif ($stops >= 1 && (
                    ($flight['stopCount'] == $stops && $flight['stopCount_back'] <= $stops) ||
                    ($flight['stopCount_back'] == $stops && $flight['stopCount'] <= $stops)
                )) {
                    $filteredFlights[] = [
                        'filtered_index' => count($filteredFlights),
                        'original_index' => $originalIndex,
                        'price_difference' => $priceDifference,
                        'flight' => $flight,
                    ];
                }

            }
        }

        $uniqueStops = array_values(array_unique($allStops));
        sort($uniqueStops);

        return response()->json([
            'filters' => [
                'stops' => $uniqueStops,
            ],
            'data' => $filteredFlights,
        ], 200);
    }

    public function updateFlight($batchId, Request $request)
    {
        $request->validate([
            'flight_index' => ['required', 'numeric'],
        ]);

        $package = Package::where('batch_id', $batchId)->first();

        if (! $package) {
            return response()->json(['message' => 'Incorrect batch id.'], 400);
        }

        $outboundFlight = $package->outboundFlight;
        $inboundFlight = $package->inboundFlight;

        $flights = json_decode($outboundFlight->all_flights, true);

        if (isset($flights['otherApiFlights'])) {
            $flights = array_merge($flights, $flights['otherApiFlights']);
            unset($flights['otherApiFlights']);
        }

        $flightIndex = $request->input('flight_index');

        if (! isset($flights[$flightIndex])) {
            return response()->json(['message' => 'Invalid flight index.'], 400);
        }

        $selectedFlight = $flights[$flightIndex];
        $oldPrice = $outboundFlight->price;
        $newPrice = $selectedFlight['price'];
        $priceDifference = $newPrice - $oldPrice;

        DB::beginTransaction();

        $outboundFlight->update([
            'price' => $newPrice,
            'departure' => $selectedFlight['departure'],
            'arrival' => $selectedFlight['arrival'],
            'airline' => $selectedFlight['airline'],
            'stop_count' => $selectedFlight['stopCount'],
            'origin' => $selectedFlight['origin'],
            'destination' => $selectedFlight['destination'],
            'adults' => $selectedFlight['adults'],
            'children' => $selectedFlight['children'],
            'infants' => $selectedFlight['infants'],
            'extra_data' => json_encode($selectedFlight),
            'segments' => $selectedFlight['segments'],
        ]);

        $inboundFlight->update([
            'price' => $newPrice,
            'departure' => $selectedFlight['departure_flight_back'],
            'arrival' => $selectedFlight['arrival_flight_back'],
            'airline' => $selectedFlight['airline_back'],
            'stop_count' => $selectedFlight['stopCount_back'],
            'origin' => $selectedFlight['origin_back'],
            'destination' => $selectedFlight['destination_back'],
            'adults' => $selectedFlight['adults'],
            'children' => $selectedFlight['children'],
            'infants' => $selectedFlight['infants'],
            'extra_data' => json_encode($selectedFlight),
            'segments' => $selectedFlight['segments_back'],
        ]);

        $packages = Package::where('outbound_flight_id', $outboundFlight->id)->get();

        foreach ($packages as $package) {
            $offers = $package->hotelData->offers ?? [];

            foreach ($offers as $offer) {
                $offer->total_price_for_this_offer += $priceDifference;
                $offer->save();
            }

            $package->total_price += $priceDifference;
            $package->save();
        }

        DB::commit();

        return response()->json(['message' => 'Success'], 200);
    }

    private function manualLiveSearch(LivesearchRequest $request, FlightsAction $flights, HotelsAction $hotels, PackagesAction $packagesAction, $destination, $origin, $destinationOrigin, $packageConfig)
    {
        $departureDate = $request->date;
        $nights = $request->nights;
        $returnDate = Carbon::parse($departureDate)->addDays($nights)->toDateString();
        $rooms = $request->rooms;
        $adults = collect($rooms)->sum('adults');
        $children = collect($rooms)->sum('children');
        $infants = collect($rooms)->sum('infants');

        $packages = Package::withTrashed()
            ->where([
                ['package_config_id', $packageConfig->id],
            ])
            ->whereHas('outboundFlight', function ($query) use ($departureDate, $adults, $children, $infants) {
                $query->whereDate('departure', $departureDate)
                    ->where('adults', '>=', $adults)
                    ->where('children', '>=', $children)
                    ->where('infants', '>=', $infants);
            })
            ->whereHas('inboundFlight', function ($query) use ($returnDate, $adults, $children, $infants) {
                $query->whereDate('departure', $returnDate)
                    ->where('adults', '>=', $adults)
                    ->where('children', '>=', $children)
                    ->where('infants', '>=', $infants);
            })
            ->when($request->price_range, function ($query) use ($request) {
                $query->whereBetween('total_price', [$request->price_range[0], $request->price_range[1]]);
            })
            ->when($request->review_scores, function ($query) use ($request) {
                $query->whereHas('hotelData.hotel', function ($query) use ($request) {
                    $query->where('review_score', '>=', $request->review_scores);
                });
            })
            ->when($request->stars, function ($query) use ($request) {
                $query->whereHas('hotelData.hotel', function ($query) use ($request) {
                    $query->whereIn('stars', $request->stars);
                });
            })
            ->when($request->room_basis, function ($query) use ($request) {
                $query->whereHas('hotelData.offers', function ($query) use ($request) {
                    $query->whereIn('room_basis', $request->room_basis);
                });
            })
            ->with([
                'hotelData',
                'hotelData.hotel',
                'hotelData.hotel.transfers',
                'hotelData.hotel.hotelPhotos',
                'outboundFlight',
                'inboundFlight',
                'hotelData.offers' => function ($query) use ($request) {
                    $query->when($request->room_basis, function ($query) use ($request) {
                        $query->whereIn('room_basis', $request->room_basis);
                    })
                        ->orderBy('price', 'asc');
                },
            ])
            ->get()
            ->sortBy(function (Package $package) {
                return $package->hotelData->offers->first()->total_price_for_this_offer;
            })
            ->values()
            ->all();

        $packages = collect($packages);

        $page = $request->page ?? 1;
        $perPage = $request->per_page ?? 10;

        $paginatedData = $packages->forPage($page, $perPage)->values();

        return response()->json([
            'data' => [
                'content_id' => isset($packages[0]->package_config_id) ? $packages[0]->package_config_id : false,
                'data' => $paginatedData,
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $packages->count(),
            ],
        ]);
    }

    public function adsShow($id)
    {
        $ad = Ad::findOrFail($id);

        $ad->load('inboundFlight', 'outboundFlight', 'hotelData.offers', 'hotelData.hotel', 'hotelData.hotel.hotelPhotos');

        return response()->json([
            'data' => $ad,
        ]);
    }

    public function mapHotels(Request $request)
    {
        // Handle both regular search and live search scenarios
        $query = Package::query();

        // If batch_id is provided (live search scenario)
        if ($request->has('batch_id')) {
            $query->withTrashed()->where('batch_id', $request->batch_id);
        }
        // Regular search scenario
        else {
            $request->validate([
                'nights' => 'required|integer',
                'checkin_date' => 'required|date|date_format:Y-m-d',
                'origin_id' => 'required|exists:origins,id',
                'destination_id' => 'required|exists:destinations,id',
            ]);

            $destination_origin = DestinationOrigin::where('destination_id', $request->destination_id)
                ->where('origin_id', $request->origin_id)
                ->first();

            $query->whereHas('packageConfig', function ($q) use ($destination_origin) {
                $q->where('destination_origin_id', $destination_origin->id);
            })->whereHas('hotelData', function ($q) use ($request) {
                $q->where('check_in_date', $request->checkin_date)
                    ->where('number_of_nights', $request->nights);
            });
        }

        // Apply filters if provided
        $query->when($request->price_range, function ($q) use ($request) {
            $q->whereBetween('total_price', [$request->price_range[0], $request->price_range[1]]);
        })
            ->when($request->review_scores, function ($q) use ($request) {
                $q->whereHas('hotelData.hotel', function ($query) use ($request) {
                    $query->where('review_score', '>=', $request->review_scores);
                });
            })
            ->when($request->stars, function ($q) use ($request) {
                $q->whereHas('hotelData.hotel', function ($query) use ($request) {
                    $query->whereIn('stars', $request->stars);
                });
            })
            ->when($request->room_basis, function ($q) use ($request) {
                $q->whereHas('hotelData.offers', function ($query) use ($request) {
                    $query->whereIn('room_basis', $request->room_basis);
                });
            });

        // Get all matching packages with hotel data
        $packages = $query->with(['hotelData.hotel'])->get();

        // Extract unique hotels with their map data
        $hotels = $packages->map(function ($package) {
            $hotel = $package->hotelData->hotel;

            // Only return hotels with valid coordinates
            if (! $hotel || ! $hotel->latitude || ! $hotel->longitude ||
                ! is_numeric($hotel->latitude) || ! is_numeric($hotel->longitude)) {
                return null;
            }

            return [
                'id' => $hotel->id,
                'name' => $hotel->name,
                'address' => $hotel->address,
                'latitude' => $hotel->latitude,
                'longitude' => $hotel->longitude,
                'stars' => $hotel->stars,
                'review_score' => $hotel->review_score,
                'review_count' => $hotel->review_count,
            ];
        })
            ->filter() // Remove null values
            ->unique('id') // Remove duplicate hotels
            ->values(); // Reset keys

        return response()->json([
            'data' => $hotels,
            'total' => $hotels->count(),
        ], 200);
    }
}
