<?php

namespace App\Filament\Resources\CommissionRuleResource\Pages;

use App\Filament\Resources\CommissionRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCommissionRules extends ListRecords
{
    protected static string $resource = CommissionRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
