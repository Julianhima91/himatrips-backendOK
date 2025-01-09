<?php

namespace App\Filament\Resources\AdConfigResource\Pages;

use App\Filament\Resources\AdConfigResource;
use App\Models\AdConfig;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAdConfig extends EditRecord
{
    protected static string $resource = AdConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['airports']) && ! empty($data['airports'])) {
            $airports = $data['airports'];

            unset($data['airports']);

            $adConfig = AdConfig::create($data);

            $adConfig->airports()->sync($airports);
        }

        return $data;
    }
}
