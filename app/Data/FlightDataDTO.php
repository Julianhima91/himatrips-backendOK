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
        public string $destination,
        public \DateTime $departure,
        public \DateTime $arrival,
        public string $airline,
        public int $stopCount,
        public int $adults,
        public int $children,
        public int $infants,
        public int|null|Optional $packageConfigId,
        public string $segments,
        public ?array $carriers,
        public ?array $timeBetweenFlights,
    ) {}
}
