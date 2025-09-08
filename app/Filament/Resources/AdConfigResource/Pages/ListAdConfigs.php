<?php

namespace App\Filament\Resources\AdConfigResource\Pages;

use App\Filament\Resources\AdConfigResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdConfigs extends ListRecords
{
    protected static string $resource = AdConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
