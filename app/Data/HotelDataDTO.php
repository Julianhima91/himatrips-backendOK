<?php

namespace App\Data;

use Carbon\Carbon;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class HotelDataDTO extends Data
{
    public function __construct(
        //id, hotel_id, check_in_date, number_of_nights, room_count, adults, children, (collection) hotel_offers
        public int|null|Optional $id,
        public int $hotel_id,
        public Carbon $check_in_date,
        public int $number_of_nights,
        public int $room_count,
        public int $adults,
        public int $children,
        public int $infants,
        /** @var \App\Data\HotelOfferDTO[] */
        public array $hotel_offers
    ) {}
}
