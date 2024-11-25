<?php

namespace App\Actions;

use App\Models\Destination;
use App\Models\DestinationOrigin;
use App\Models\Origin;
use App\Models\PackageConfig;

class DestinationOriginAction
{
    public function handle()
    {
        $origins = Origin::all();

        $destinations = Destination::query()->where('is_active', true)->get();

        foreach ($origins as $origin) {
            foreach ($destinations as $destination) {
                $exists = DestinationOrigin::where('origin_id', $origin->id)
                    ->where('destination_id', $destination->id)
                    ->exists();

                if (! $exists) {
                    $destinationOrigin = DestinationOrigin::create([
                        'origin_id' => $origin->id,
                        'destination_id' => $destination->id,
                    ]);

                    $hasPackageConfig = PackageConfig::query()
                        ->where('destination_origin_id', $destinationOrigin->id)->first();

                    if (! $hasPackageConfig) {
                        PackageConfig::create([
                            'destination_origin_id' => $destinationOrigin->id,
                            'commission_amount' => 80,
                            'commission_percentage' => 10,
                        ]);
                    }
                }
            }
        }
    }
}
