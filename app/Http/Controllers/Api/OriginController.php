<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Origin;
use DateTime;
use Spatie\QueryBuilder\QueryBuilder;

class OriginController extends Controller
{
    public function index()
    {
        $origins = QueryBuilder::for(Origin::class)
            ->whereHas('destinationOrigin', function ($query) {
                $query->whereHas('packageConfigs', function ($pivotQuery) {
                    return $pivotQuery;
                });
            })
            ->orderBy('search_count', 'desc')
            ->get();

        if ($origins->isEmpty()) {
            return response()->json([
                'message' => 'No origins found',
            ], 404);
        }

        //make resource for filtering if needed

        return response()->json([
            'data' => $origins,
        ], 200);
    }

    public function availableOrigins()
    {
        $uniqueOrigins = \Cache::remember('available_origins', 180, function () {
            return Origin::query()
                ->select(['id', 'name', 'description', 'city', 'country'])
                ->with(['destinationOrigin.packages.outboundFlight', 'destinationOrigin.packages.inboundFlight', 'destinationOrigin.packages.packageConfig'])
                ->whereHas('destinationOrigin.packages')
                ->get()
                ->map(function ($origin) {
                    $allPackages = $origin->destinationOrigin
                        ->flatMap(function ($destinationOrigin) {
                            return $destinationOrigin->packages;
                        });

                    $filteredPackages = $allPackages->filter(function ($package) {
                        $outboundFlight = $package->outboundFlight;
                        $inboundFlight = $package->inboundFlight;

                        if (! $outboundFlight || ! $inboundFlight) {
                            return false;
                        }

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
                        return [
                            'id' => $origin->id,
                            'name' => $origin->name,
                            'description' => $origin->description,
                            'city' => $origin->city,
                            'country' => $origin->country,
                        ];
                    }

                    return null;
                })
                ->filter()
                ->values();
        });

        return response()->json([
            'data' => $uniqueOrigins,
        ], 200);
    }
}
