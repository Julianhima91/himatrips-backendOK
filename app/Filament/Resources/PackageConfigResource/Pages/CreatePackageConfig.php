<?php

namespace App\Filament\Resources\PackageConfigResource\Pages;

use App\Filament\Resources\PackageConfigResource;
use App\Models\DestinationOrigin;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePackageConfig extends CreateRecord
{
    protected static string $resource = PackageConfigResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $commissionRate = $data['commission_amount'];
        $commissionPercentage = $data['commission_percentage'];
        $data['destination_origin_id'] = [];
        foreach ($data['destination_create'] as $destination) {
            $destinationOrigin = DestinationOrigin::where('origin_id', $data['origin'])->where('destination_id', $destination)->first();

            $data['destination_origin_id'][] = $destinationOrigin->id;
        }
        //set the destination_origin_id
        //$data['number_of_nights'] = explode(',', $data['number_of_nights']);
        //$data['origin_airports'] = json_encode($data['origin_airports']);
        //$data['destination_airports'] = json_encode($data['destination_airports']);
        //$data['airlines'] = json_encode($data['airlines']);

        unset($data['origin']);
        unset($data['destination_create']);

        return $data;
    }

    protected function handleRecordCreation($data): Model
    {
        foreach ($data['destination_origin_id'] as $destinationOriginId) {
            $record = new ($this->getModel())([
                'airlines' => $data['airlines'],
                'is_direct_flight' => $data['is_direct_flight'],
                'prioritize_morning_flights' => $data['prioritize_morning_flights'],
                'prioritize_evening_flights' => $data['prioritize_evening_flights'],
                'max_wait_time' => $data['max_wait_time'],
                'max_stop_count' => $data['max_stop_count'],
                'max_transit_time' => $data['max_transit_time'],
                'min_nights_stay' => $data['min_nights_stay'],
                'room_basis' => $data['room_basis'],
                'commission_percentage' => $data['commission_percentage'],
                'commission_amount' => $data['commission_amount'],
                'destination_origin_id' => $destinationOriginId,
            ]);

            if (
                static::getResource()::isScopedToTenant() &&
                ($tenant = Filament::getTenant())
            ) {
                return $this->associateRecordWithTenant($record, $tenant);
            }

            $record->save();
        }

        return $record;
    }
}
