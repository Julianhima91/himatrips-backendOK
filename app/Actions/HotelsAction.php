<?php

namespace App\Actions;

use App\Models\HotelData;
use App\Models\HotelOffer;
use Illuminate\Support\Facades\Cache;

class HotelsAction
{
    public function handle($destination, $outbound_flight_hydrated, $inbound_flight_hydrated)
    {
        //array of hotel data DTOs
        $hotel_results = Cache::get('hotels');

        \Log::info($hotel_results);
        $package_ids = [];

        $commission_percentage = $destination->commission_percentage != 0 ? $destination->commission_percentage : 0.2;

        foreach ($hotel_results as $hotel_result) {

            $hotel_data = HotelData::create([
                'hotel_id' => $hotel_result->hotel_id,
                'check_in_date' => $hotel_result->check_in_date,
                'number_of_nights' => $hotel_result->number_of_nights,
                'room_count' => $hotel_result->room_count,
                'adults' => $hotel_result->adults,
                'children' => $hotel_result->children,
                'infants' => $hotel_result->infants,
                'package_config_id' => 0,
            ]);

            foreach ($hotel_result->hotel_offers as $offer) {

                HotelOffer::create([
                    'hotel_data_id' => $hotel_data->id,
                    'room_basis' => $offer->room_basis,
                    'room_type' => json_encode($offer->room_type),
                    'price' => $offer->price,
                    'total_price_for_this_offer' => $outbound_flight_hydrated->price + $inbound_flight_hydrated->price + $offer->price + $commission_percentage * ($outbound_flight_hydrated->price + $inbound_flight_hydrated->price + $offer->price),
                    'reservation_deadline' => $offer->reservation_deadline,
                ]);
            }

            $first_offer = $hotel_data->offers()->orderBy('price')->first();
            $cheapestOffer = collect($hotel_data->offers)->sortBy('TotalPrice')->first();

            $hotel_data->update(['price' => $cheapestOffer->total_price_for_this_offer]);
        }

        return [$hotel_data, $first_offer];
    }
}
