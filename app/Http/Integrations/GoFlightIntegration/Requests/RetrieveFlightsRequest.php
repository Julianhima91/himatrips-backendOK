<?php

namespace App\Http\Integrations\GoFlightIntegration\Requests;

use App\Data\FlightDataDTO;
use DateTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\SoloRequest;
use Saloon\RateLimitPlugin\Contracts\RateLimitStore;
use Saloon\RateLimitPlugin\Limit;
use Saloon\RateLimitPlugin\Stores\LaravelCacheStore;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;
use Spatie\LaravelData\Optional;

class RetrieveFlightsRequest extends SoloRequest
{
    use AlwaysThrowOnErrors;

    /**
     * Define the HTTP method
     */
    protected Method $method = Method::GET;

    // public ?int $tries = 2;

    public function __construct() {}

    /**
     * Define the endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return 'https://flights-search3.p.rapidapi.com/flights/search-roundtrip';
    }

    protected function defaultQuery(): array
    {
        return [
            // 'access_key' => config('goflight.access_key'),
            'currency' => 'EUR',
            // 'adults' => 2,
        ];
    }

    public function createDtoFromResponse($response): mixed
    {
        $data = $response->json();

        // go through data itineraries and create DTOs
        return $flightItineraryDTOs = collect($data['data']['itineraries'])->map(function ($itinerary) {

            $carriers = $this->getCarriers($itinerary);

            // check that every element in the array is the same
            if (count(array_unique($carriers)) != 1) {
                return null;
            }

            return new FlightDataDTO(
                id: Optional::create(),
                price: (float) $itinerary['price']['raw'],
                origin: $itinerary['legs'][0]['origin']['id'],
                origin_back: $itinerary['legs'][1]['origin']['id'],
                destination: $itinerary['legs'][0]['destination']['id'],
                destination_back: $itinerary['legs'][1]['destination']['id'],
                departure: new DateTime($itinerary['legs'][0]['departure']),
                arrival: new DateTime($itinerary['legs'][0]['arrival']),
                departure_flight_back: new DateTime($itinerary['legs'][1]['departure']),
                arrival_flight_back: new DateTime($itinerary['legs'][1]['arrival']),
                airline: $itinerary['legs'][0]['carriers']['marketing'][0]['name'],
                airline_back: $itinerary['legs'][1]['carriers']['marketing'][0]['name'],
                stopCount: $itinerary['legs'][0]['stopCount'],
                stopCount_back: $itinerary['legs'][1]['stopCount'],
                adults: $this->query()->get('adults'),
                children: $this->query()->get('children'),
                infants: $this->query()->get('infants'),
                packageConfigId: Optional::create(),
                segments: json_encode($itinerary['legs'][0]['segments']),
                segments_back: json_encode($itinerary['legs'][1]['segments']),
                carriers: $this->getCarriers($itinerary),
                timeBetweenFlights: $this->getTimeBetweenFlights($itinerary) ?? 0
            );
        });

    }

    protected function defaultHeaders(): array
    {
        return [
            'X-RapidAPI-Key' => 'eff37b01a1msh6090de6dea39514p108435jsnf7c09e43a0a5',
            'X-RapidAPI-Host' => 'flights-search3.p.rapidapi.com',
        ];
    }

    private function getCarriers($itinerary): array
    {
        return collect($itinerary['legs'][0]['carriers']['marketing'])->pluck('id')->toArray();
    }

    private function getTimeBetweenFlights($itinerary): array
    {
        $timeBetweenFlights = [];

        foreach ($itinerary['legs'] as $leg) {
            $segments = $leg['segments'];
            for ($i = 0; $i < count($segments) - 1; $i++) {
                $arrivalTime = $segments[$i]['arrival'];
                // get the departure time for the next segment
                $departureTime = $segments[$i + 1]['departure'];
                // get the difference in minutes; example value is 2023-12-02T15:40:00
                $diffInMinutes = Carbon::parse($departureTime)->diffInMinutes(Carbon::parse($arrivalTime));
                $timeBetweenFlights[] = $diffInMinutes;
            }
        }

        return $timeBetweenFlights;
    }

    //    protected function resolveLimits(): array
    //    {
    //        return [
    //            Limit::allow(5000)->everySeconds(1)->sleep(),
    //        ];
    //    }
    //
    //    protected function resolveRateLimitStore(): RateLimitStore
    //    {
    //        return new LaravelCacheStore(Cache::store('redis'));
    //    }

}
