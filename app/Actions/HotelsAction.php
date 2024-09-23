<?php

namespace App\Actions;

use App\Models\HotelData;
use App\Models\HotelOffer;
use App\Models\Package;
use App\Models\PackageConfig;
use Illuminate\Support\Facades\Cache;

class HotelsAction
{
    public function handle($destination, $outbound_flight_hydrated, $inbound_flight_hydrated, $batchId)
    {
        //array of hotel data DTOs
        $hotel_results = Cache::get('hotels');

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
            //calculate commission (20%)
            //$commission = ($outbound_flight_hydrated->price + $inbound_flight_hydrated->price + $first_offer->price) * $commission_percentage;
            $packageConfig = PackageConfig::query()
                ->whereHas('destination_origin', function ($query) {
                    $query->where([
                        ['destination_id', request()->destination_id],
                        ['origin_id', request()->origin_id],
                    ]);
                })->first();

            $calculatedCommissionPercentage = ($packageConfig->commission_percentage / 100) * $first_offer->total_price_for_this_offer;
            $fixedCommissionRate = $packageConfig->commission_amount;
            //create the package here
            $package = Package::create([
                'hotel_data_id' => $hotel_data->id,
                'outbound_flight_id' => $outbound_flight_hydrated->id,
                'inbound_flight_id' => $inbound_flight_hydrated->id,
                'commission' => max($fixedCommissionRate, $calculatedCommissionPercentage),
                'total_price' => $first_offer->total_price_for_this_offer,
                'batch_id' => $batchId,
                'package_config_id' => $packageConfig->id ?? null,
            ]);
            $package_ids[] = $package->id;
        }

        return $package_ids;
    }
}
