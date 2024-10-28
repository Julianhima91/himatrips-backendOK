<?php

namespace App\Filament\Resources\PackageSearchesResource\Pages;

use App\Filament\Resources\ClientSearchesResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPackageSearches extends EditRecord
{
    protected static string $resource = ClientSearchesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
