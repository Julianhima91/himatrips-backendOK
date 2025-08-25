<?php

namespace App\Filament\Pages;

use Boquizo\FilamentLogViewer\Pages\ListLogs as BaseListLogs;
use Filament\Tables\Table;

class CustomListLogs extends BaseListLogs
{
    protected static ?string $navigationLabel = 'Application Logs';

    protected static string|null|\UnitEnum $navigationGroup = 'Monitoring';

    public static function table(Table $table): Table
    {
        return parent::table($table)
            ->defaultPaginationPageOption(25)
            ->poll('30s');
    }
}
