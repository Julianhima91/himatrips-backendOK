<?php

namespace App\Actions;

use App\Http\Requests\CheckFlightAvailabilityRequest;
use App\Jobs\CheckFlightAvailabilityJob;

class CheckFlightAvailability
{
    public function handle(CheckFlightAvailabilityRequest $request): bool
    {
        CheckFlightAvailabilityJob::dispatch($request);

        return true;
    }
}
