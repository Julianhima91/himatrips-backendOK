<?php

namespace App\Actions;

use App\Models\Package;
use App\Models\PackageConfig;

class PackagesAction
{
    public function handle($first_offer, $outbound_flight_hydrated, $inbound_flight_hydrated, $hotel_data, $batchId)
    {
        //todo: default package config?
        //array of hotel data DTOs
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

        $maxTotalPrice = Package::whereIn('id', $package_ids)->max('total_price');
        $minTotalPrice = Package::whereIn('id', $package_ids)->min('total_price');

        $packages = Package::whereIn('id', $package_ids)
            ->with([
                'hotelData',
                'hotelData.hotel',
                'hotelData.hotel.hotelPhotos',
                'outboundFlight',
                'inboundFlight',
                'hotelData.offers' => function ($query) {
                    $query->orderBy('price', 'asc');
                },
            ])
            ->paginate(10);

        return [$packages, $minTotalPrice, $maxTotalPrice];
    }
}
