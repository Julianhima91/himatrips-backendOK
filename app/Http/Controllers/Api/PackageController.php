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
use App\Jobs\LiveSearchHotels;
use App\Models\Airport;
use App\Models\Destination;
use App\Models\DestinationOrigin;
use App\Models\DirectFlightAvailability;
use App\Models\Package;
use App\Models\PackageConfig;
use Carbon\Carbon;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        ray()->newScreen();

        $return_date = Carbon::parse($request->date)->addDays($request->nights)->format('Y-m-d');

        $date = $request->date;

        //get the airports here
        $origin_airport = Airport::query()->where('origin_id', $request->origin_id)->first();
        $destination_airport = Airport::query()->whereHas('destinations', function ($query) use ($request) {
            $query->where('destination_id', $request->destination_id);
        })->first();

        $destination = Destination::where('id', $request->destination_id)->first();

        try {
            $batchId = Str::orderedUuid();

            Cache::put("job_completed_{$batchId}", false, now()->addMinutes(1));
            Cache::put("hotel_job_completed_{$batchId}", false, now()->addMinutes(1));

            $jobs = [
                new LiveSearchFlightsApi2($origin_airport, $destination_airport, $date, $return_date, $origin_airport, $destination_airport, $request->adults, $request->children, $request->infants, $batchId),
                new LiveSearchFlights($request->date, $return_date, $origin_airport, $destination_airport, $request->adults, $request->children, $request->infants, $batchId),
                new LiveSearchHotels($request->date, $request->nights, $request->destination_id, $request->adults, $request->children, $request->infants, $request->rooms, $batchId),
            ];

            foreach ($jobs as $job) {
                Bus::dispatch($job);
            }

            // Continuously check the shared state until one job completes
            while (true) {
                if (Cache::get("job_completed_{$batchId}") && Cache::get("hotel_job_completed_{$batchId}")) {
                    // One job has completed, break the loop
                    ray('job completed');

                    [$outbound_flight_hydrated, $inbound_flight_hydrated] = $flights->handle($date, $destination, $batchId, $return_date);

                    if (is_null($outbound_flight_hydrated) || is_null($inbound_flight_hydrated)) {
                        broadcast(new LiveSearchFailed('No flights found', $batchId));

                        break;
                    }
                    $package_ids = $hotels->handle($destination, $outbound_flight_hydrated, $inbound_flight_hydrated, $batchId);
                    [$packages, $minTotalPrice, $maxTotalPrice] = $packagesAction->handle($package_ids);

                    //fire off event
                    broadcast(new LiveSearchCompleted($packages, $batchId, $minTotalPrice, $maxTotalPrice));

                    break;
                }

                sleep(1); // Sleep for a bit to avoid high CPU usage
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Live search started', 'data' => [
            'batch_id' => $batchId,
        ]], 200);
    }

    public function show(Package $package)
    {
        //we need to return the whole package here
        //this will include the hotel data, hotel photos, flight data

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

        $packageConfig = PackageConfig::query()
            ->where('destination_origin_id', $destination_origin->id)->first();

        $minNightsStay = $packageConfig->min_nights_stay;

        $directFlightDates = DirectFlightAvailability::query()
            ->where([
                ['destination_origin_id', $destination_origin->id],
                ['is_return_flight', 1],
                ['date', '>', Carbon::parse($request->start_date)->addDays($minNightsStay)],
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

        //lets modify this so we automatically get the first month and year
        //if the user has not selected a month and year
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

    public function paginateLiveSearch(Request $request)
    {
        $packages = Package::where('batch_id', $request->batch_id)
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
        $cheapestPackages = Destination::query()
            ->select(['id', 'name', 'description', 'city', 'country', 'created_at', 'updated_at', 'show_in_homepage'])
            ->with(['destinationOrigin.packages.outboundFlight', 'destinationOrigin.packages.packageConfig.destination_origin'])
            ->whereHas('destinationOrigin.packages')
            ->get()
            ->map(function ($destination) use ($request) {
                $allPackages = $destination->destinationOrigin
                    ->filter(function ($origin) use ($request) {
                        return $origin->origin_id === ((int)($request->origin_id ?? 1));
                    })
                    ->flatMap(function ($origin) {
                        return $origin->packages;
                    });

                $filteredPackages = $allPackages->filter(function ($package) {
                    $outboundFlight = $package->outboundFlight;
                    $inboundFlight = $package->inboundFlight;

                    $outboundDate = new DateTime($outboundFlight->departure);
                    $inboundDate = new DateTime($inboundFlight->departure);
                    $nightsStay = $inboundDate->diff($outboundDate)->days;

                    return $nightsStay >= 2
                        && $outboundFlight->adults == 2
                        && $outboundFlight->children == 0
                        && $outboundFlight->infants == 0;
                });

                $cheapestPackage = $filteredPackages->sortBy('total_price')->first();

                if ($cheapestPackage) {
                    return array_merge(
                        $destination->only(['id', 'name', 'description', 'city', 'country', 'created_at', 'updated_at', 'show_in_homepage']),
                        ['price' => $cheapestPackage->total_price],
                        ['batch_id' => $cheapestPackage->batch_id],
                        ['photos' => $destination->destinationPhotos],
                        ['destination_origin' => $cheapestPackage->packageConfig->destination_origin]
                    );
                }

                return null;
            })
            ->filter()
            ->values();

        return response()->json([
            'data' => $cheapestPackages,
        ], 200);
    }
}
