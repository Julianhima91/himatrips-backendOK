<?php

namespace App\Filament\Pages;

use Boquizo\FilamentLogViewer\Pages\ListLogs as BaseListLogs;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;

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

    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        return parent::getTableRecords()->sortByDesc('all');
    }
}
