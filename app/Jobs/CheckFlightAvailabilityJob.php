<?php

namespace App\Jobs;

use App\Http\Integrations\GoFlightIntegration\Requests\OneWayDirectFlightRequest;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\DirectFlightAvailability;
use App\Models\PackageConfig;
use Carbon\CarbonPeriod;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class CheckFlightAvailabilityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $request;

    /**
     * Create a new job instance.
     */
    public function __construct($request)
    {
        $this->request = $request;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $packageConfig = PackageConfig::find($this->request->package_config_id);
        $origin = $packageConfig->destination_origin->origin;
        $destination = $packageConfig->destination_origin->destination;

        $origin_airport = Airport::query()->where('origin_id', $origin->id)->first();
        $destination_airport = Airport::query()->whereHas('destinations', function ($query) use ($destination) {
            $query->where('destination_id', $destination->id);
        })->first();

        $airlineName = $this->request->airline_id ? Airline::find($this->request->airline_id)->nameAirline : null;

        $period = CarbonPeriod::create($this->request->from_date, $this->request->to_date);

        foreach ($period as $date) {
            $this->searchDirectFlight($origin_airport, $destination_airport, $date->format('Y-m-d'), $airlineName, $packageConfig->destination_origin_id, false);
        }

        $returnPeriod = CarbonPeriod::create(Carbon::parse($this->request->from_date)->addDay(), Carbon::parse($this->request->to_date)->addDay());

        foreach ($returnPeriod as $date) {
            $this->searchDirectFlight($destination_airport, $origin_airport, $date->format('Y-m-d'), $airlineName, $packageConfig->destination_origin_id, true);
        }
    }

    private function searchDirectFlight($origin_airport, $destination_airport, $date, $airlineName, $destination_origin_id, $is_return_flight): void
    {
        $flightRequest = new OneWayDirectFlightRequest;

        $flightRequest->query()->merge([
            'fromEntityId' => $origin_airport->rapidapi_id,
            'toEntityId' => $destination_airport->rapidapi_id,
            'departDate' => $date,
            'stops' => 'direct',
        ]);

        try {
            $response = $flightRequest->send();

            $itineraries = $response->json()['data']['itineraries'] ?? [];

            if ($this->hasDirectFlight($itineraries, $airlineName)) {
                DirectFlightAvailability::updateOrCreate([
                    'date' => $date,
                    'destination_origin_id' => $destination_origin_id,
                    'is_return_flight' => $is_return_flight,
                ]);

                Log::info(($is_return_flight ? 'Return' : 'Outbound').' direct flight available on date: '.$date);
            } else {
                Log::info('No itineraries found for date: '.$date);
            }
        } catch (\Exception $e) {
            Log::error('!!!ERROR!!!');
            Log::error($e->getMessage());
        }
    }

    private function hasDirectFlight(array $itineraries, ?string $airlineName): bool
    {
        foreach ($itineraries as $itinerary) {
            if (! $airlineName ||
                ($itinerary['legs'][0]['carriers']['marketing'][0]['name'] === $airlineName)) {
                return true;
            }
        }

        return false;
    }
}
