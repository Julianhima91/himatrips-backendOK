<?php

namespace App\Jobs;

use App\Http\Integrations\GoFlightIntegration\Requests\DirectIncompleteFlightRequest;
use App\Http\Integrations\GoFlightIntegration\Requests\OneWayDirectFlightRequest;
use App\Models\DirectFlightAvailability;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckFlightAvailabilityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $origin_airport;

    private $destination_airport;

    private $date;

    private $airlineName;

    private $destination_origin_id;

    private $is_return_flight;

    /**
     * Create a new job instance.
     */
    public function __construct($origin_airport, $destination_airport, $date, $airlineName, $destination_origin_id, $is_return_flight)
    {
        $this->origin_airport = $origin_airport;
        $this->destination_airport = $destination_airport;
        $this->date = $date;
        $this->airlineName = $airlineName;
        $this->destination_origin_id = $destination_origin_id;
        $this->is_return_flight = $is_return_flight;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $flightRequest = new OneWayDirectFlightRequest;

        $flightRequest->query()->merge([
            'fromEntityId' => $this->origin_airport->rapidapi_id,
            'toEntityId' => $this->destination_airport->rapidapi_id,
            'departDate' => $this->date,
            'stops' => 'direct',
        ]);

        try {
            $response = $flightRequest->send();

            if (isset($response->json()['data']['context']['status']) && $response->json()['data']['context']['status'] == 'incomplete') {
                $response = $this->getIncompleteResults($response->json()['data']['context']['sessionId']);
            }

            Log::info('Status: '.$response->json()['data']['context']['status']);
            $itineraries = $response->json()['data']['itineraries'] ?? [];

            if ($this->hasDirectFlight($itineraries, $this->airlineName)) {
                DirectFlightAvailability::updateOrCreate([
                    'date' => $this->date,
                    'destination_origin_id' => $this->destination_origin_id,
                    'is_return_flight' => $this->is_return_flight,
                ]);

                Log::info(($this->is_return_flight ? 'Return' : 'Outbound').' direct flight available on date: '.$this->date);
            } else {
                Log::info('No itineraries found for date: '.$this->date);
            }
        } catch (Exception $e) {
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

    private function getIncompleteResults($session)
    {
        $request = new DirectIncompleteFlightRequest;

        $request->query()->merge([
            'sessionId' => $session,
            'stops' => 'direct',
        ]);

        $response = $request->send();

        if (isset($response->json()['data']['context']['status']) && $response->json()['data']['context']['status'] == 'incomplete') {
            return $this->getIncompleteResults($session);
        }

        return $response;
    }
}
