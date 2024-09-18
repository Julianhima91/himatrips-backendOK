<?php

namespace App\Data;

use Carbon\Carbon;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class HotelOfferDTO extends Data
{
    public function __construct(
        public int|null|Optional $id,
        public int $hotel_data_id,
        public string $room_basis,
        public array $room_type,
        public float $price,
        public ?Carbon $reservation_deadline,
        public ?string $remark
    ) {}
}
