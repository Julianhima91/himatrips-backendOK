<?php

namespace App\Filament\Resources\AdConfigResource\Pages;

use App\Filament\Resources\AdConfigResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAdConfigs extends ListRecords
{
    protected static string $resource = AdConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
