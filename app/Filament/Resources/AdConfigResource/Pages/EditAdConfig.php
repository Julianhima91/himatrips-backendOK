<?php

namespace App\Filament\Resources\AdConfigResource\Pages;

use App\Filament\Resources\AdConfigResource;
use App\Jobs\UpdateEconomicAdDestinationJob;
use App\Jobs\UpdateHolidayAdDestinationJob;
use App\Jobs\UpdateWeekendAdDestinationJob;
use App\Models\AdConfig;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\EditRecord;

class EditAdConfig extends EditRecord
{
    protected static string $resource = AdConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),

            Action::make('openModal')
                ->label('Update Economic')
                ->modalHeading('Select an Option')
                ->form([
                    Select::make('temp_destination_id')
                        ->label('Destination')
                        ->options(fn () => $this->record->destinations()->pluck('name', 'destinations.id'))
                        ->required(),
                ])
                ->action(function (array $data) {
                    UpdateEconomicAdDestinationJob::dispatch($data['temp_destination_id'], $this->record);
                })
                ->modalSubmitActionLabel('Confirm'),

            Action::make('openModal')
                ->label('Update Holidays')
                ->modalHeading('Select an Option')
                ->form([
                    Select::make('holiday_destination_id')
                        ->label('Destination')
                        ->options(fn () => $this->record->destinations()->pluck('name', 'destinations.id'))
                        ->required(),
                ])
                ->action(function (array $data) {
                    UpdateHolidayAdDestinationJob::dispatch($data['holiday_destination_id'], $this->record)->onQueue('holiday');
                })
                ->modalSubmitActionLabel('Confirm'),

            Action::make('openModal')
                ->label('Update Weekends')
                ->modalHeading('Select an Option')
                ->form([
                    Select::make('holiday_destination_id')
                        ->label('Destination')
                        ->options(fn () => $this->record->destinations()->pluck('name', 'destinations.id'))
                        ->required(),
                ])
                ->action(function (array $data) {
                    UpdateWeekendAdDestinationJob::dispatch($data['holiday_destination_id'], $this->record)->onQueue('weekend');
                })
                ->modalSubmitActionLabel('Confirm'),
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
