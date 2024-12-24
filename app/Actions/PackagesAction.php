<?php

namespace App\Actions;

use App\Models\Package;

class PackagesAction
{
    public function handle($package_ids)
    {
        $maxTotalPrice = Package::whereIn('id', $package_ids)->max('total_price');
        $minTotalPrice = Package::whereIn('id', $package_ids)->min('total_price');
        $packageConfigId = Package::whereIn('id', $package_ids)->first()->package_config_id;

        $packages = Package::whereIn('id', $package_ids)
            ->with([
                'hotelData',
                'hotelData.hotel',
                'hotelData.hotel.transfers',
                'hotelData.hotel.hotelPhotos',
                'outboundFlight',
                'inboundFlight',
                'tags',
                'hotelData.offers' => function ($query) {
                    $query->orderBy('price', 'asc');
                },
            ])
            ->paginate(10);

        return [$packages, $minTotalPrice, $maxTotalPrice, $packageConfigId];
    }
}
