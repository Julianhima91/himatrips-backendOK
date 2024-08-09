<?php

namespace App\Http\Controllers\Api;

use App\Events\LiveSearchCompleted;
use App\Events\LiveSearchFailed;
use App\Http\Controllers\Controller;
use App\Jobs\LiveSearchFlights;
use App\Jobs\LiveSearchFlightsApi2;
use App\Jobs\LiveSearchHotels;
use App\Models\Airport;
use App\Models\Destination;
use App\Models\DestinationOrigin;
use App\Models\Flight;
use App\Models\FlightData;
use App\Models\Hotel;
use App\Models\HotelData;
use App\Models\HotelOffer;
use App\Models\Origin;
use App\Models\Package;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PackageController extends Controller
{
    public function search(Request $request)
    {
        // todo: Validate the request: nights, checkin_date, origin, destination. Use Request
        $request->validate([
            'nights' => 'required|integer',
            'checkin_date' => 'required|date|date_format:Y-m-d',
            'origin_id' => 'required|string',
            'destination_id' => 'required|string|exists:destination_origins,destination_id',
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

    public function liveSearch(Request $request)
    {
        //here we need parameters for the flight and the hotel which will be
        //hotel: checkin_date, nights, destination, adults, children

        $request->validate([
            'nights' => 'required|integer',
            'date' => 'required|date|date_format:Y-m-d',
            'from_airport' => 'required|string|exists:airports,id',
            'to_airport' => 'required|string|exists:airports,id',
            'origin_id' => 'required|string|exists:origins,id',
            'destination_id' => 'required|string|exists:destinations,id',
            'adults' => 'required|integer',
            'children' => 'required|integer',
            'infants' => 'required|integer',
        ]);

        ray()->newScreen();

        $return_date = Carbon::parse($request->date)->addDays($request->nights)->format('Y-m-d');

        $date = $request->date;

        //get the airports here
        $origin_airport = Airport::query()->where('origin_id', $request->origin_id)->first();
        $destination_airport = Airport::query()->whereHas('destinations', function ($query) use ($request) {
            $query->where('destination_id', $request->destination_id);
        })->first();

        $destination = Destination::where('id', $request->destination_id)->first();

        $fromAirport = Airport::query()->find($request->from_airport);
        $toAirport = Airport::query()->find($request->to_airport);

        try {
            Cache::put('job_completed', false, now()->addMinutes(10));

            $jobs = [
                new LiveSearchFlightsApi2($fromAirport, $toAirport, $date, $return_date, $origin_airport, $destination_airport, $request->adults, $request->children, $request->infants),
                new LiveSearchFlights($request->date, $return_date, $origin_airport, $destination_airport, $request->adults, $request->children, $request->infants),
                new LiveSearchHotels($request->date, $request->nights, $request->destination_id, $request->adults, $request->children, $request->infants),
            ];

            foreach ($jobs as $job) {
                // Dispatch each job
                Bus::dispatch($job);
            }

            // Continuously check the shared state until one job completes
            while (true) {
                if (Cache::get('job_completed')) {
                    // One job has completed, break the loop
                    ray('job completed!!!!!!!!!!!!!!!!!');

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
                        broadcast(new LiveSearchFailed('No flights found', '$batchId'));

                        return;
                    }

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
                        'package_config_id' => 0,
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
                        broadcast(new LiveSearchFailed('No flights found', '$batchId'));

                        return;
                    }

                    $first_inbound_flight = $inbound_flight->first();

                    $inbound_flight_hydrated = FlightData::create([
                        'price' => $first_inbound_flight->price,
                        'departure' => $first_inbound_flight->departure,
                        'arrival' => $first_inbound_flight->arrival,
                        'airline' => $first_inbound_flight->airline,
                        'stop_count' => $first_inbound_flight->stopCount,
                        'origin' => $first_inbound_flight->origin,
                        'destination' => $first_inbound_flight->destination,
                        'adults' => $first_inbound_flight->adults,
                        'children' => $first_inbound_flight->children,
                        'infants' => $first_inbound_flight->infants,
                        'extra_data' => json_encode($first_inbound_flight),
                        'segments' => $first_inbound_flight->segments,
                        'package_config_id' => 0,
                    ]);

                    //array of hotel data DTOs
                    $hotel_results = Cache::get('hotels');

                    $package_ids = [];

                    $commission_percentage = $destination->commission_percentage != 0 ? $destination->commission_percentage : 0.2;

                    foreach ($hotel_results as $hotel_result) {

                        $hotel_data = HotelData::create([
                            'hotel_id' => $hotel_result->hotel_id,
                            'check_in_date' => $hotel_result->check_in_date,
                            'number_of_nights' => $hotel_result->number_of_nights,
                            'room_count' => $hotel_result->room_count,
                            'adults' => $hotel_result->adults,
                            'children' => $hotel_result->children,
                            'infants' => $hotel_result->infants,
                            'package_config_id' => 0,
                        ]);

                        foreach ($hotel_result->hotel_offers as $offer) {

                            HotelOffer::create([
                                'hotel_data_id' => $hotel_data->id,
                                'room_basis' => $offer->room_basis,
                                'room_type' => $offer->room_type,
                                'price' => $offer->price,
                                'total_price_for_this_offer' => $outbound_flight_hydrated->price + $inbound_flight_hydrated->price + $offer->price + $commission_percentage * ($outbound_flight_hydrated->price + $inbound_flight_hydrated->price + $offer->price),
                                'reservation_deadline' => $offer->reservation_deadline,
                            ]);
                        }

                        $first_offer = $hotel_data->offers()->orderBy('price')->first();

                        //calculate commission (20%)
                        $commission = ($outbound_flight_hydrated->price + $inbound_flight_hydrated->price + $first_offer->price) * $commission_percentage;

                        //create the package here
                        $package = Package::create([
                            'hotel_data_id' => $hotel_data->id,
                            'outbound_flight_id' => $outbound_flight_hydrated->id,
                            'inbound_flight_id' => $inbound_flight_hydrated->id,
                            'commission' => $commission,
                            'total_price' => $first_offer->total_price_for_this_offer,
                            'batch_id' => '$batchId',
                        ]);

                        $package_ids[] = $package->id;
                    }

                    $maxTotalPrice = Package::whereIn('id', $package_ids)->max('total_price');
                    $minTotalPrice = Package::whereIn('id', $package_ids)->min('total_price');

                    $packages = Package::whereIn('id', $package_ids)
                        ->with([
                            'hotelData',
                            'hotelData.hotel',
                            'hotelData.hotel.hotelPhotos',
                            'outboundFlight',
                            'inboundFlight',
                            'hotelData.offers' => function ($query) {
                                $query->orderBy('price', 'asc');
                            },
                        ])
                        ->paginate(10);

                    //fire off event
                    broadcast(new LiveSearchCompleted($packages, '$batchId', $minTotalPrice, $maxTotalPrice));

                    break;
                }

                sleep(1); // Sleep for a bit to avoid high CPU usage
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Live search started', 'data' => [
            'batch_id' => 'test',
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

    public function getAvailableDates(Request $request)
    {
        $destination_origin = DestinationOrigin::where('destination_id', $request->destination_id)
            ->where('origin_id', $request->origin_id)
            ->first();

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

        $packages = collect($packages)
            ->paginate(
                perPage: $request->per_page ?? 10,
                page: $request->page ?? 1
            );

        return response()->json([
            'data' => $packages,
        ], 200);
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
}
