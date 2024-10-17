<?php

namespace App\Filament\Resources\PackageConfigResource\RelationManagers;

use App\Models\DirectFlightAvailability;
use Coolsam\FilamentFlatpickr\Enums\FlatpickrMode;
use Coolsam\FilamentFlatpickr\Forms\Components\Flatpickr;
use Filament\Forms;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class DirectFlightAvailabilityRelationManager extends RelationManager
{
    protected static string $relationship = 'directFlightsAvailabilityDates';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('date')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('date')
            ->columns([
                Tables\Columns\TextColumn::make('date'),
                Tables\Columns\ToggleColumn::make('is_return_flight')
                    ->label('Return Flight')
                    ->disabled(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add Dates')
                    ->form([
                        Flatpickr::make('dates')
                            ->label('Select Dates')
                            ->minDate('today')
                            ->maxDate(now()->addYear())
                            ->mode(FlatpickrMode::MULTIPLE),
                        Toggle::make('is_return_flight')
                            ->label('Return Flight'),
                    ])
                    ->action(function (array $data) {
                        $dates = explode(',', $data['dates']);

                        foreach ($dates as $date) {
                            DirectFlightAvailability::updateOrCreate(
                                [
                                    'date' => $date,
                                    'destination_origin_id' => $this->ownerRecord->destination_origin_id,
                                    'is_return_flight' => $data['is_return_flight'],
                                ]
                            );
                        }

                        Notification::make()
                            ->success()
                            ->title('Dates inserted successfully!')
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
