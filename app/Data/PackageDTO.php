<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class PackageDTO extends Data
{
    public float $price_minus_hotel;

    public function __construct(
        //
        public int|null|Optional $id,
        public FlightDataDTO $outboundFlight,
        public FlightDataDTO $inboundFlight,
        public HotelDataDTO $hotelData,
        public float $commission,
        public float $total_price,
        public int $package_config_id,
    ) {
        $this->price_minus_hotel = $this->total_price - $this->hotelData->hotel_offers[0]->price;
    }
}
