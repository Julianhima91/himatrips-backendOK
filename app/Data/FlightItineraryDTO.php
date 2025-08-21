<?php

namespace App\Data;

use DateTime;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class FlightItineraryDTO extends Data
{
    public function __construct(
        //
        public int|Optional|null $id,
        public float $price,
        public string $airline,
        public int $stopCount,
        public DateTime $departure,
        public DateTime $arrival,
    ) {}
}
