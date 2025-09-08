<?php

namespace App\Filament\Resources\AirportResource\Pages;

use App\Filament\Resources\AirportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAirports extends ListRecords
{
    protected static string $resource = AirportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
