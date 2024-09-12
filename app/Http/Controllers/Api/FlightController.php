<?php

namespace App\Http\Controllers\Api;

use App\Actions\CheckFlightAvailability;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckFlightAvailabilityRequest;

class FlightController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(CheckFlightAvailabilityRequest $request, CheckFlightAvailability $action)
    {
        $result = $action->handle($request);

        return $result ? response()->json(['message' => 'Direct flights availability updated.'])
            : response()->json(['message' => 'No direct flights were found.']);
    }
}
