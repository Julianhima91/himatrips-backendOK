<?php

namespace App\Filament\Resources\DestinationResource\Pages;

use App\Filament\Resources\DestinationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditDestination extends EditRecord
{
    protected static string $resource = DestinationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        //get the image from the data
        $image = $data['Images'];
        unset($data['Images']);
        $record->update($data);

        //delete all photos first from the database and from storage
        $record->destinationPhotos()->delete();

        if ($image) {
            foreach ($image as $file) {
                $record->destinationPhotos()->updateOrCreate([
                    'file_path' => $file,
                    'destination_id' => $record->id,
                ]);
            }
        }

        return $record;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $model = static::getModel();

        $data['Images'] = $model::find($data['id'])->destinationPhotos->pluck('file_path')->toArray();

        return $data;
    }
}
