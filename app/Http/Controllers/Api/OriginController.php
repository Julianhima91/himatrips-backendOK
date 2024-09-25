<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Origin;
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
