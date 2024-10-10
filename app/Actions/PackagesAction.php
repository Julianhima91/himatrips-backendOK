<?php

namespace App\Actions;

use App\Models\Package;

class PackagesAction
{
    public function handle($package_ids)
    {
        $maxTotalPrice = Package::whereIn('id', $package_ids)->max('total_price');
        $minTotalPrice = Package::whereIn('id', $package_ids)->min('total_price');

        $packages = Package::whereIn('id', $package_ids)
            ->with([
                'hotelData',
                'hotelData.hotel',
                'hotelData.hotel.transfers',
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
