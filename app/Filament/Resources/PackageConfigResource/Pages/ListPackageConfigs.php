<?php

namespace App\Filament\Resources\PackageConfigResource\Pages;

use App\Filament\Resources\PackageConfigResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPackageConfigs extends ListRecords
{
    protected static string $resource = PackageConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
