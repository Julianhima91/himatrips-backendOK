<?php

namespace App\Filament\Resources\HotelResource\Pages;

use App\Filament\Resources\HotelResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateHotel extends CreateRecord
{
    protected static string $resource = HotelResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $images = $data['Images'];
        unset($data['Images']);
        $hotel = static::getModel()::create($data);

        foreach ($images as $image) {
            $hotel->hotelPhotos()->create([
                'file_path' => $image,
                'hotel_id' => $hotel->id,
            ]);
        }

        return $hotel;
    }
}
