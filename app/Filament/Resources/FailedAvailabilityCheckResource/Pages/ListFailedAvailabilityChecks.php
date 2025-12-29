<?php

namespace App\Filament\Resources\FailedAvailabilityCheckResource\Pages;

use App\Filament\Resources\FailedAvailabilityCheckResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFailedAvailabilityChecks extends ListRecords
{
    protected static string $resource = FailedAvailabilityCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('retry_all')
                ->label('Retry All Failed Checks')
                ->icon('heroicon-o-arrow-path')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Retry All Failed Checks')
                ->modalDescription('This will retry all failed availability checks. This may take some time.')
                ->action(function () {
                    \App\Jobs\RetryFailedAvailabilityJob::dispatch();

                    \Filament\Notifications\Notification::make()
                        ->success()
                        ->title('Retry Job Dispatched')
                        ->body('All failed checks are being retried in the background.')
                        ->send();
                }),
        ];
    }
}

