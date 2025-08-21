<?php

namespace App\Filament\Resources\DestinationPhotosResource\Pages;

use App\Filament\Resources\DestinationPhotosResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDestinationPhotos extends ListRecords
{
    protected static string $resource = DestinationPhotosResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
