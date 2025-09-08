<?php

namespace App\Filament\Resources\DestinationOriginResource\Pages;

use App\Filament\Resources\DestinationOriginResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDestinationOrigins extends ListRecords
{
    protected static string $resource = DestinationOriginResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
