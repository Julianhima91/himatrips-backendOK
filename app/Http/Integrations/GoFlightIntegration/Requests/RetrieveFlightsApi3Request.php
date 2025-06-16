<?php

namespace App\Http\Integrations\GoFlightIntegration\Requests;

use App\Data\FlightDataDTO;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\SoloRequest;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;
use Spatie\LaravelData\Optional;

class RetrieveFlightsApi3Request extends SoloRequest
{
    use AlwaysThrowOnErrors;

    /**
     * Define the HTTP method
     */
    protected Method $method = Method::GET;

    //public ?int $tries = 2;

    public function __construct(
        public string $departure_airport_code,
        public string $arrival_airport_code,
        public string $departure_date,
        public string $arrival_date,
        public int $number_of_adults,
        public int $number_of_childrens,
        public int $number_of_infants,
    ) {}

    /**
     * Define the endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return sprintf(
            'https://api.flightapi.io/roundtrip/%s/%s/%s/%s/%s/%s/%s/%s/Economy/EUR',
            config('app.flight_api_key_3'),
            $this->departure_airport_code,
            $this->arrival_airport_code,
            $this->departure_date,
            $this->arrival_date,
            $this->number_of_adults,
            $this->number_of_childrens,
            $this->number_of_infants,
        );
    }

    public function createDtoFromResponse($response): mixed
    {
        $data = $response->json();

        $legs = collect($data['legs'])->keyBy('id');
        $segments = collect($data['segments'])->keyBy('id');
        $places = collect($data['places'])->keyBy('id');
        $carriers = collect($data['carriers'])->keyBy('id');

        return collect($data['itineraries'])->map(function ($itinerary) use ($legs, $segments, $places, $carriers) {
            if (count($itinerary['leg_ids']) !== 2) {
                return null;
            }

            $legOut = $legs[$itinerary['leg_ids'][0]] ?? null;
            $legBack = $legs[$itinerary['leg_ids'][1]] ?? null;

            if (! $legOut || ! $legBack) {
                return null;
            }

            $carrierIdsOut = $legOut['marketing_carrier_ids'] ?? [];
            $carrierIdsBack = $legBack['marketing_carrier_ids'] ?? [];

            $carriersOut = collect($carrierIdsOut)->map(fn ($id) => $carriers[$id]['name'] ?? 'Unknown')->toArray();
            $carriersBack = collect($carrierIdsBack)->map(fn ($id) => $carriers[$id]['name'] ?? 'Unknown')->toArray();

            if (count(array_unique([...$carrierIdsOut, ...$carrierIdsBack])) !== 1) {
                return null;
            }

            return new FlightDataDTO(
                id: Optional::create(),
                price: (float) $itinerary['cheapest_price']['amount'],
                origin: $places[$legOut['origin_place_id']]['display_code'] ?? 'Unknown',
                origin_back: $places[$legBack['origin_place_id']]['display_code'] ?? 'Unknown',
                destination: $places[$legOut['destination_place_id']]['display_code'] ?? 'Unknown',
                destination_back: $places[$legBack['destination_place_id']]['display_code'] ?? 'Unknown',
                departure: new \DateTime($legOut['departure']),
                arrival: new \DateTime($legOut['arrival']),
                departure_flight_back: new \DateTime($legBack['departure']),
                arrival_flight_back: new \DateTime($legBack['arrival']),
                airline: $carriersOut[0] ?? 'Unknown',
                airline_back: $carriersBack[0] ?? 'Unknown',
                stopCount: $legOut['stop_count'],
                stopCount_back: $legBack['stop_count'],
                adults: $this->number_of_adults,
                children: $this->number_of_childrens,
                infants: $this->number_of_infants,
                packageConfigId: Optional::create(),
                segments: json_encode(collect($legOut['segment_ids'])->map(fn ($id) => $segments[$id] ?? [])),
                segments_back: json_encode(collect($legBack['segment_ids'])->map(fn ($id) => $segments[$id] ?? [])),
                carriers: array_merge($carrierIdsOut, $carrierIdsBack),
                timeBetweenFlights: $this->getTimeBetweenFlights([$legOut, $legBack], $segments) ?? []
            );
        })->filter();
    }

    private function getTimeBetweenFlights(array $legs, Collection $segments): array
    {
        $timeBetweenFlights = [];

        foreach ($legs as $leg) {
            if (! isset($leg['segment_ids'])) {
                continue;
            }

            $segmentIds = $leg['segment_ids'];

            $legSegments = collect($segmentIds)
                ->map(fn ($id) => $segments[$id] ?? null)
                ->filter()
                ->values();

            for ($i = 0; $i < $legSegments->count() - 1; $i++) {
                $arrivalTime = $legSegments[$i]['arrival'];
                $departureTime = $legSegments[$i + 1]['departure'];
                $diffInMinutes = Carbon::parse($departureTime)->diffInMinutes(Carbon::parse($arrivalTime));
                $timeBetweenFlights[] = $diffInMinutes;
            }
        }

        return $timeBetweenFlights;
    }
}
