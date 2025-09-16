<?php

namespace App\Filament\Pages;

use Boquizo\FilamentLogViewer\Pages\ViewLog as BaseViewLog;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

class CustomViewLog extends BaseViewLog
{
    public function getHeaderActions(): array
    {
        return array_merge(parent::getHeaderActions(), [
            //            Action::make('export')
            //                ->label('Export to CSV')
            //                ->icon(Heroicon::OutlinedArrowDownTray)
            //                ->action(fn () => $this->exportToCsv()),
        ]);
    }

    private function exportToCsv(): void
    {
        // todo: in case we include it
    }
}
