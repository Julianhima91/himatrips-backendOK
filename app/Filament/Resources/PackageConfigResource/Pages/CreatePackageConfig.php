<?php

namespace App\Filament\Resources\PackageConfigResource\Pages;

use App\Filament\Resources\PackageConfigResource;
use App\Models\DestinationOrigin;
use Filament\Resources\Pages\CreateRecord;

class CreatePackageConfig extends CreateRecord
{
    protected static string $resource = PackageConfigResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        //find the correct DestinationOrigin model
        $destinationOrigin = DestinationOrigin::where('origin_id', $data['origin'])->where('destination_id', $data['destination'])->first();

        //set the destination_origin_id
        $data['destination_origin_id'] = $destinationOrigin->id;
        $data['number_of_nights'] = explode(',', $data['number_of_nights']);
        //$data['origin_airports'] = json_encode($data['origin_airports']);
        //$data['destination_airports'] = json_encode($data['destination_airports']);
        //$data['airlines'] = json_encode($data['airlines']);

        unset($data['origin']);
        unset($data['destination']);

        return $data;
    }
}
