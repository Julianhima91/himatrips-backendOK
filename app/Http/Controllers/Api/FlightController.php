<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DirectFlightAvailability;
use App\Models\PackageConfig;
use Illuminate\Http\Request;

class FlightController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
        ]);

        $results = PackageConfig::query()
            ->where('max_stop_count', '=', 0)
            ->whereDate('from_date', '<=', $validated['date'])
            ->whereDate('to_date', '>=', $validated['date'])
            ->get();

        foreach ($results as $result) {
            DirectFlightAvailability::updateOrCreate(
                [
                    'date' => $validated['date'],
                    'destination_origin_id' => $result->destination_origin_id,
                ],
            );
        }

        return $results->isEmpty() ? response()->json(['message' => 'No direct flights were found.'])
            : response()->json(['message' => 'Direct flights availability updated.']);
    }
}
