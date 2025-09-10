<?php

namespace App\Actions;

use App\Models\HotelData;
use App\Models\HotelOffer;
use App\Models\Package;
use App\Models\PackageConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class HotelsAction
{
    public function handle($destination, $outbound_flight_hydrated, $inbound_flight_hydrated, $batchId, $origin_id, $destination_id, $roomObject)
    {
        $logger = Log::channel('livesearch');

        // array of hotel data DTOs
        $hotel_results = Cache::get("hotels:{$batchId}");

        $packageConfig = PackageConfig::query()
            ->whereHas('destination_origin', function ($query) use ($origin_id, $destination_id) {
                $query->where([
                    ['destination_id', $destination_id],
                    ['origin_id', $origin_id],
                ]);
            })->first();

        $package_ids = [];

        if (empty($hotel_results) || count($hotel_results) === 0) {
            $logger->warning("No hotel results found for batch ID: {$batchId}".
                ($packageConfig ? " (Package Config ID: {$packageConfig->id})" : ''));

            return ['success' => false];
        }

        foreach ($hotel_results as $hotel_result) {
            $hotel_data = HotelData::create([
                'hotel_id' => $hotel_result->hotel_id,
                'check_in_date' => $hotel_result->check_in_date,
                'number_of_nights' => $hotel_result->number_of_nights,
                'room_count' => $hotel_result->room_count,
                'adults' => $hotel_result->adults,
                'children' => $hotel_result->children,
                'infants' => $hotel_result->infants,
                'package_config_id' => $packageConfig->id,
                'room_object' => json_encode($roomObject),
            ]);

            $transferPrice = 0;
            foreach ($hotel_data->hotel->transfers as $transfer) {
                $transferPrice += $transfer->adult_price * $hotel_result->adults;

                if ($hotel_result->children > 0) {
                    $transferPrice += $transfer->children_price * $hotel_result->children;
                }
            }

            $batchOffers = [];

            foreach ($hotel_result->hotel_offers as $offer) {
                $calculatedCommissionPercentage = ($packageConfig->commission_percentage / 100) * ($outbound_flight_hydrated->price + $transferPrice + $offer->price);
                $fixedCommissionRate = $packageConfig->commission_amount;
                $commission = max($fixedCommissionRate, $calculatedCommissionPercentage);

                $batchOffers[] = [
                    'hotel_data_id' => $hotel_data->id,
                    'room_basis' => $offer->room_basis,
                    'room_type' => json_encode($offer->room_type),
                    'price' => $offer->price,
                    'total_price_for_this_offer' => $outbound_flight_hydrated->price + $transferPrice + $offer->price + $commission,
                    'reservation_deadline' => $offer->reservation_deadline,
                ];
            }

            HotelOffer::insert($batchOffers);

            $first_offer = $hotel_data->offers()->orderBy('price')->first();
            $cheapestOffer = collect($hotel_data->offers)->sortBy('TotalPrice')->first();

            $hotel_data->update(['price' => $cheapestOffer->total_price_for_this_offer + $transferPrice]);
            // calculate commission (20%)
            // $commission = ($outbound_flight_hydrated->price + $inbound_flight_hydrated->price + $first_offer->price) * $commission_percentage;

            // $calculatedCommissionPercentage = ($packageConfig->commission_percentage / 100) * $first_offer->total_price_for_this_offer;
            // $fixedCommissionRate = $packageConfig->commission_amount;
            // $commission = max($fixedCommissionRate, $calculatedCommissionPercentage);
            // create the package here
            $package = Package::create([
                'hotel_data_id' => $hotel_data->id,
                'outbound_flight_id' => $outbound_flight_hydrated->id,
                'inbound_flight_id' => $inbound_flight_hydrated->id,
                'commission' => $commission,
                'total_price' => $first_offer->total_price_for_this_offer,
                'batch_id' => $batchId,
                'package_config_id' => $packageConfig->id ?? null,
            ]);

            $package_ids[] = $package->id;
        }

        return $package_ids;
    }
}
