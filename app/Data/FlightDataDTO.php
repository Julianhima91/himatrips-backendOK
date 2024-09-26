<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class FlightDataDTO extends Data
{
    public function __construct(
        //
        public int|Optional|null $id,
        public float $price,
        public string $origin,
        public string $origin_back,
        public string $destination,
        public string $destination_back,
        public \DateTime $departure,
        public \DateTime $arrival,
        public \DateTime $departure_flight_back,
        public \DateTime $arrival_flight_back,
        public string $airline,
        public string $airline_back,
        public int $stopCount,
        public int $stopCount_back,
        public int $adults,
        public int $children,
        public int $infants,
        public int|null|Optional $packageConfigId,
        public string $segments,
        public ?array $carriers,
        public ?array $timeBetweenFlights,
    ) {}
}
