<?php

namespace App\Http\Controllers\Api;

use App\Actions\FlightsAction;
use App\Actions\HotelsAction;
use App\Actions\PackagesAction;
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
        // todo: Validate the request: nights, checkin_date, origin, destination. Use Request
        $request->validate([
            'nights' => 'required|integer',
            'checkin_date' => 'required|date|date_format:Y-m-d',
            'origin_id' => 'required|exists:origins,id',
            'destination_id' => 'required|exists:destinations,id',
        ]);

        $destination_origin = DestinationOrigin::where('destination_id', $request->destination_id)
            ->where('origin_id', $request->origin_id)
            ->first();

        $packages = Package::whereHas('packageConfig', function ($query) use ($destination_origin) {
            $query->where('destination_origin_id', $destination_origin->id);
        })->whereHas('hotelData', function ($query) use ($request) {
            $query->where('check_in_date', $request->checkin_date)
                ->where('number_of_nights', $request->nights);
        })
            ->join('hotel_data', 'packages.hotel_data_id', '=', 'hotel_data.id')
            ->select('packages.*', DB::raw('(packages.total_price - hotel_data.price) as price_minus_hotel'))
            ->with(['hotelData', 'hotelData.hotel', 'hotelData.hotel.hotelPhotos', 'outboundFlight', 'inboundFlight', 'packageConfig:id,last_processed_at'])
            ->orderBy('total_price')
            ->paginate(10);

        if ($packages->isEmpty()) {
            return response()->json(['message' => 'No packages found'], 404);
        }

        return response()->json(['data' => $packages], 200);
    }

    public function liveSearch(LivesearchRequest $request, FlightsAction $flights, HotelsAction $hotels, PackagesAction $packagesAction)
    {
        $logger = Log::channel('livesearch');
        $totalAdults = collect($request->input('rooms'))->pluck('adults')->sum();
        $totalChildren = collect($request->input('rooms'))->pluck('children')->sum();
        $totalInfants = collect($request->input('rooms'))->pluck('infants')->sum();
        ray()->newScreen();

        $return_date = Carbon::parse($request->date)->addDays((int) $request->nights)->format('Y-m-d');

        $date = $request->date;

        // get the airports here
        $origin_airport = Airport::query()->where('origin_id', $request->origin_id)->first();
        $destination_airport = Airport::query()->whereHas('destinations', function ($query) use ($request) {
            $query->where('destination_id', $request->destination_id);
        })->first();

        $destination = Destination::where('id', $request->destination_id)->first();
        $origin = Origin::where('id', $request->origin_id)->first();

        $destinationOrigin = DestinationOrigin::query()
            ->where([
                ['origin_id', $origin->id],
                ['destination_id', $destination->id],
            ])->first();

        $packageConfig = PackageConfig::query()
            ->where('destination_origin_id', $destinationOrigin->id)
            ->first();

        if ($packageConfig->is_manual) {
            return $this->manualLiveSearch($request, $flights, $hotels, $packagesAction, $destination, $origin, $destinationOrigin, $packageConfig);
        }

        try {
            $batchId = Str::orderedUuid();

            Cache::put("job_completed_{$batchId}", false, now()->addMinutes(1));
            Cache::put("hotel_job_completed_{$batchId}", false, now()->addMinutes(1));

            $longFlightDestinations = ['Maldives', 'Zanzibar', 'Bali', 'Thailand'];

            if (in_array($destination->name, $longFlightDestinations)) {
                $return_date = Carbon::parse($return_date)->addDay()->format('Y-m-d');

                $hotelStartDate = Carbon::parse($date)->addDay()->format('Y-m-d');
            } else {
                $hotelStartDate = $date;
            }

            $jobs = [
                // new LiveSearchFlightsApi2($origin_airport, $destination_airport, $date, $return_date, $origin_airport, $destination_airport, $totalAdults, $totalChildren, $totalInfants, $batchId),
                new LiveSearchFlights($date, $return_date, $origin_airport, $destination_airport, $totalAdults, $totalChildren, $totalInfants, $batchId),
                new LiveSearchFlightsApi3($date, $return_date, $origin_airport, $destination_airport, $totalAdults, $totalChildren, $totalInfants, $batchId),
                new LiveSearchHotels($hotelStartDate, $request->nights, $request->destination_id, $totalAdults, $totalChildren, $totalInfants, $request->rooms, $batchId, $origin->country_code ?? 'AL'),
            ];

            foreach ($jobs as $job) {
                Bus::dispatch($job);
            }
            //            $startTime = microtime(true);
            //            Log::info("Start time: {$startTime} seconds");

            //            dd('end');
            // Continuously check the shared state until one job completes
            while (true) {
                if (Cache::get("job_completed_{$batchId}") && Cache::get("hotel_job_completed_{$batchId}")) {
                    // One job has completed, break the loop
                    //                    $jobsFinished = microtime(true);
                    //                    $jobsElapsed = $jobsFinished - $startTime;
                    //                    Log::info("Jobs finished time: {$jobsElapsed} seconds");

                    ray('job completed');

                    [$outbound_flight_hydrated, $inbound_flight_hydrated] = $flights->handle($date, $destination, $batchId, $return_date, $request->origin_id, $request->destination_id);
                    //                    $flightsFinished = microtime(true);
                    //                    $flightsElapsed = $flightsFinished - $jobsFinished;
                    //                    Log::info("Flights finished time: {$flightsElapsed} seconds");

                    if (is_null($outbound_flight_hydrated) || is_null($inbound_flight_hydrated)) {
                        broadcast(new LiveSearchFailed('No flights found', $batchId));
                        $logger->warning('======================================');
                        $logger->warning("$batchId Broadcasting failed sent. FLIGHTS NULL");
                        $logger->warning('======================================');

                        $logger->warning('REQUEST:');
                        $logger->warning(json_encode($request->all(), JSON_PRETTY_PRINT));

                        return response()->json([
                            'success' => false,
                            'message' => 'No flights found.',
                            'batch_id' => $batchId,
                        ], 204);
                    }

                    $package_ids = $hotels->handle($destination, $outbound_flight_hydrated, $inbound_flight_hydrated, $batchId, $request->origin_id, $request->destination_id, $request->input('rooms'));
                    //                    $hotelsFinished = microtime(true);
                    //                    $hotelsElapsed = $hotelsFinished - $flightsFinished;
                    //                    Log::info("Hotels finished time: {$hotelsElapsed} seconds");

                    $firstBoardOption = $destination->board_options ?? null;
                    [$packages, $minTotalPrice, $maxTotalPrice, $packageConfigId] = $packagesAction->handle($package_ids, $firstBoardOption);

                    // fire off event
                    broadcast(new LiveSearchCompleted($packages, $batchId, $minTotalPrice, $maxTotalPrice, $packageConfigId, $firstBoardOption));
                    $logger->info('======================================');
                    $logger->info('Broadcasting sent. SUCCESS');
                    $logger->info('======================================');

                    //                    $queriesFinished = microtime(true);
                    //                    $queriesElapsed = $queriesFinished - $jobsFinished;
                    //                    Log::info("Queries finished time: {$queriesElapsed} seconds");

                    break;
                }
            }

            //            $endTime = microtime(true);
            //            $totalElapsed = $endTime - $startTime;
            //            Log::info("Total elapsed time: {$totalElapsed} seconds");
        } catch (Throwable $e) {
            $logger->error($e->getMessage());

            return response()->json(['message' => $e->getMessage()], 500);
        }

        $logger->info('Response sent');

        return response()->json(['message' => 'Live search started', 'data' => [
            'batch_id' => $batchId,
        ]], 200);
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
        $request->validate([
            'start_date' => ['required', 'date', 'after_or_equal:today'],
        ]);

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

        $minNightsStay = PackageConfig::query()
            ->where('destination_origin_id', $destination_origin->id)->first()
            ->destination_origin->destination->min_nights_stay;

        $directFlightDates = DirectFlightAvailability::query()
            ->where([
                ['destination_origin_id', $destination_origin->id],
                ['is_return_flight', 1],
                ['date', '>=', Carbon::parse($request->start_date)->addDays($minNightsStay)],
            ])
            ->orderBy('date')
            ->take(15)
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
