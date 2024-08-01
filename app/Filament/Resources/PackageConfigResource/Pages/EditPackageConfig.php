<?php

namespace App\Filament\Resources\PackageConfigResource\Pages;

use App\Filament\Resources\PackageConfigResource;
use App\Models\DestinationOrigin;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPackageConfig extends EditRecord
{
    protected static string $resource = PackageConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $model = static::getModel();

        $data['origin'] = $model::find($data['id'])->destination_origin->origin_id;
        $data['destination'] = $model::find($data['id'])->destination_origin->destination_id;
        //$data['number_of_nights'] = implode(',', $model::find($data['id'])->number_of_nights);
        //$data['origin_airports'] = json_decode($model::find($data['id'])->origin_airports);
        //$data['destination_airports'] = json_decode($model::find($data['id'])->destination_airports);
        //$data['airlines'] = json_decode($model::find($data['id'])->airlines);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['destination_origin_id'] = DestinationOrigin::where('origin_id', $data['origin'])
            ->where('destination_id', $data['destination'])
            ->first()
            ->id;

        unset($data['origin']);
        unset($data['destination']);

        return $data;
    }
}
