<?php

namespace App\Filament\Resources\AdConfigResource\Pages;

use App\Filament\Resources\AdConfigResource;
use App\Models\AdConfig;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAdConfig extends CreateRecord
{
    protected static string $resource = AdConfigResource::class;

    //    protected function handleRecordCreation(array $data): Model
    //    {
    //        $airports = $data['airports'];
    //        unset($data['airports']);
    //
    //        $adConfig = AdConfig::create($data);
    //
    //        $adConfig->airports()->attach($airports);
    //
    //        return $adConfig;
    //    }
}
