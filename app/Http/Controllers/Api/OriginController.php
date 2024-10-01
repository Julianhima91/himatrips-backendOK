<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DestinationOrigin;
use App\Models\Origin;
use Spatie\QueryBuilder\QueryBuilder;

class OriginController extends Controller
{
    public function index()
    {
        $destinationOrigin = DestinationOrigin::find(30);

        dd($destinationOrigin->directFlightsAvailability);

        dd('end');
        $origins = QueryBuilder::for(Origin::class)
            ->whereHas('destinationOrigin', function ($query) {
                $query->whereHas('packageConfigs', function ($pivotQuery) {
                    return $pivotQuery;
                });
            })
            ->with('destinations')
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
}
