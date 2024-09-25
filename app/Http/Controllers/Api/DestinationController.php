<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Destination;
use App\Models\Origin;
use Illuminate\Http\Request;

class DestinationController extends Controller
{
    public function index(Request $request)
    {
        //here we will return 12 destinations that have show_on_homepage set to true
        //we also need to include the number of packages for each destination
        //we also need to include the lowest price for each destination

        $destinations = Destination::where('show_in_homepage', true)
            ->withCount('packages')
            ->withMin('packages', 'total_price')
            ->with('destinationPhotos')
            ->take(12)
            ->get();

        return response()->json([
            'data' => $destinations,
        ], 200);
    }

    public function indexAll()
    {
        //here we will return all destinations
        //we also need to include the number of packages for each destination
        //we also need to include the lowest price for each destination

        $destinations = Destination::withCount('packages')
            ->withMin('packages', 'total_price')
            ->with('destinationPhotos')
            ->get();

        return response()->json([
            'data' => $destinations,
        ], 200);
    }

    public function showPackagesForDestination(Destination $destination, Request $request)
    {
        //here we need to get all packages for this destination grouped by date, and sorted by price
        //then we will return the cheapest package for each date

        //validate that we have the origin in the request
        $request->validate([
            'origin' => 'required|exists:origins,id',
        ]);

        //get the destination origin
        $destinationOrigin = $destination->destinationOrigin()->where('origin_id', $request->origin)->first();

        if (! $destinationOrigin) {
            return response()->json([
                'data' => 'Not found',
            ]);
        }
        $sort = $request->sort ?? 'total_price';

        if ($sort == 'date') {
            $sort = 'hotelData.check_in_date';
        } else {
            $sort = 'total_price';
        }

        $packages = $destinationOrigin->packages()
            ->with('hotelData', 'hotelData.hotel', 'hotelData.hotel.hotelPhotos')
            ->get()
            ->groupBy('hotelData.check_in_date')
            ->map(function ($item) use ($sort) {
                return $item->sortBy($sort)->first();
            })
            ->filter(function ($item) {
                return $item->hotelData->check_in_date >= now()->format('Y-m-d');
            })
            ->sortBy($sort);

        return response()->json([
            'data' => $packages,
        ], 200);

    }

    public function showDestinationsForOriginPlain(Origin $origin)
    {
        $originId = $origin->id;

        $destinations = Destination::where('show_in_homepage', true)
            ->whereHas('destinationOrigin', function ($query) use ($originId) {
                $query->where('origin_id', $originId)
                    ->whereHas('packageConfigs');
            })
            ->withCount('packages')
            ->withMin('packages', 'total_price')
            ->with('destinationPhotos')
            ->take(12)
            ->get();

        return response()->json([
            'data' => $destinations,
        ], 200);
    }

    public function showDestinationsForOrigin(Origin $origin, Request $request)
    {
        $destinations = $origin->destinations()
            ->when($request->has('show_in_homepage'), function ($query) use ($request) {
                $query->where('show_in_homepage', $request->show_in_homepage);
            })
            ->whereHas('packages', function ($query) use ($origin) {
                $query->whereHas('packageConfig', function ($query) use ($origin) {
                    $query->whereHas('destination_origin', function ($query) use ($origin) {
                        $query->where('origin_id', $origin->id);
                    });
                });
            })
            ->with('destinationPhotos')
            ->withCount(['packages' => function ($query) use ($origin) {
                $query->whereHas('packageConfig', function ($query) use ($origin) {
                    $query->whereHas('destination_origin', function ($query) use ($origin) {
                        $query->where('origin_id', $origin->id);
                    });
                });
            }])
            ->withMin(['packages' => function ($query) use ($origin) {
                $query->whereHas('packageConfig', function ($query) use ($origin) {
                    $query->whereHas('destination_origin', function ($query) use ($origin) {
                        $query->where('origin_id', $origin->id);
                    });
                });
            }], 'total_price')
            ->withMin(['hotelData' => function ($query) use ($origin) {
                $query->whereHas('package', function ($query) use ($origin) {
                    $query->whereHas('packageConfig', function ($query) use ($origin) {
                        $query->whereHas('destination_origin', function ($query) use ($origin) {
                            $query->where('origin_id', $origin->id);
                        });
                    });
                });
            }], 'check_in_date')
            ->when(request('sort') == 'price', function ($query) {
                $query->orderBy('packages_min_total_price');
            })
            ->when(request('sort') == 'date', function ($query) {
                $query->orderBy('hotel_data_min_check_in_date');
            })
            ->when($request->has('limit'), function ($query) use ($request) {
                $query->take($request->limit);
            })
            ->get();

        return response()->json([
            'data' => $destinations,
            'origin' => $origin,
        ], 200);
    }
}
