<?php

namespace App\Filament\Resources\DestinationResource\Pages;

use App\Filament\Resources\DestinationResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDestination extends CreateRecord
{
    protected static string $resource = DestinationResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        if (isset($data['Images'])) {
            $image = $data['Images'];
        }

        unset($data['Images']);

        $destination = static::getModel()::create($data);

        if (! isset($image) || (is_array($image) && empty($image))) {
            return $destination;
        }

        foreach ($image as $img) {
            $destination->destinationPhotos()->updateOrCreate([
                'file_path' => $img,
                'destination_id' => $destination->id,
            ]);
        }

        return $destination;
    }
}
