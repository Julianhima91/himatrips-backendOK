<?php

namespace App\Filament\Resources\OriginResource\Pages;

use App\Filament\Resources\OriginResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOrigin extends EditRecord
{
    protected static string $resource = OriginResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
