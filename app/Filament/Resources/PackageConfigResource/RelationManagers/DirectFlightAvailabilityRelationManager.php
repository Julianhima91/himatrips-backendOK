<?php

namespace App\Filament\Resources\PackageConfigResource\RelationManagers;

use App\Models\DirectFlightAvailability;
// use Coolsam\FilamentFlatpickr\Enums\FlatpickrMode;
// use Coolsam\FilamentFlatpickr\Forms\Components\Flatpickr;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DirectFlightAvailabilityRelationManager extends RelationManager
{
    protected static string $relationship = 'directFlightsAvailabilityDates';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('date')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('date')
            ->columns([
                TextColumn::make('date'),
                ToggleColumn::make('is_return_flight')
                    ->label('Return Flight')
                    ->disabled(),
            ])
            ->filters([
                Filter::make('date_range')
                    ->schema([
                        //                        Flatpickr::make('date_range')
                        //                            ->minDate('today')
                        //                            ->maxDate(now()->addYear())
                        //                            ->mode(FlatpickrMode::RANGE),

                    ])
                    ->query(function (Builder $query, array $data) {
                        if (isset($data['date_range'])) {
                            $dates = explode(' to ', $data['date_range']);

                            if (count($dates) === 2) {
                                $startDate = $dates[0];
                                $endDate = $dates[1];

                                return $query
                                    ->where('date', '>=', $startDate)
                                    ->where('date', '<=', $endDate);
                            }
                        }

                        return $query;
                    })
                    ->label('Filter by Date Range'),
                Filter::make('return_flight')
                    ->schema([
                        Toggle::make('is_return_flight')
                            ->label('Return Flight')
                            ->default(false),
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (array_key_exists('is_return_flight', $data)) {
                            $query->where('is_return_flight', $data['is_return_flight']);
                        }

                        return $query;
                    }),

            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add Dates')
                    ->schema([
                        //                        Flatpickr::make('dates')
                        //                            ->label('Select Dates')
                        //                            ->minDate('today')
                        //                            ->maxDate(now()->addYear())
                        //                            ->mode(FlatpickrMode::MULTIPLE),
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
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
