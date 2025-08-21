<?php

namespace App\Actions;

use App\Models\Package;
use Illuminate\Pagination\LengthAwarePaginator;

class PackagesAction
{
    public function handle($package_ids, $firstBoardOption)
    {
        $maxTotalPrice = Package::whereIn('id', $package_ids)->max('total_price');
        $minTotalPrice = Package::whereIn('id', $package_ids)->min('total_price');
        $packageConfigId = Package::whereIn('id', $package_ids)->first()->package_config_id;

        $packages = Package::whereIn('id', $package_ids)
            ->when($firstBoardOption, function ($query) use ($firstBoardOption) {
                $query->whereHas('hotelData.offers', function ($query) use ($firstBoardOption) {
                    $query->whereIn('room_basis', $firstBoardOption);
                });
            })
            ->with([
                'hotelData',
                'hotelData.hotel',
                'hotelData.hotel.transfers',
                'hotelData.hotel.hotelPhotos',
                'outboundFlight',
                'inboundFlight',
                'hotelData.offers' => function ($query) use ($firstBoardOption) {
                    $query->when($firstBoardOption, function ($query) use ($firstBoardOption) {
                        $query->whereIn('room_basis', $firstBoardOption);
                    })
                        ->orderBy('price', 'asc');
                },
            ])
            ->get()
            ->sortBy(function (Package $package) {
                return optional($package->hotelData->offers->first())->total_price_for_this_offer ?? INF;
            })
            ->values();

        $page = request()->get('page', 1);
        $perPage = 10;

        $paginated = new LengthAwarePaginator(
            $packages->forPage($page, $perPage),
            $packages->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return [$paginated, $minTotalPrice, $maxTotalPrice, $packageConfigId];
    }
}
