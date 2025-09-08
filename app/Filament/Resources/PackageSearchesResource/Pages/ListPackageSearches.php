<?php

namespace App\Filament\Resources\PackageSearchesResource\Pages;

use App\Filament\Resources\ClientSearchesResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPackageSearches extends ListRecords
{
    protected static string $resource = ClientSearchesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
