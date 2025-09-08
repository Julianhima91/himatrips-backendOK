<?php

namespace App\Filament\Resources\DestinationPhotosResource\Pages;

use App\Filament\Resources\DestinationPhotosResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDestinationPhotos extends EditRecord
{
    protected static string $resource = DestinationPhotosResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
