<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AdConfigResource\Pages;
use App\Filament\Resources\AdConfigResource\RelationManagers\CSVRelationManager;
use App\Jobs\GenerateOffersForAdConfigs;
use App\Models\AdConfig;
use App\Models\Airport;
use App\Models\Destination;
use App\Models\Origin;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AdConfigResource extends Resource
{
    protected static ?string $model = AdConfig::class;

    protected static ?string $navigationGroup = 'Advertising';

    protected static ?string $navigationLabel = 'Ad Configs';

    protected static ?string $navigationIcon = 'heroicon-o-cog';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('origin_id')
                    ->label('Origin')
                    ->relationship('origin', 'name')
                    ->searchable()
                    ->reactive()
                    ->required(),

                Forms\Components\Select::make('airports')
                    ->label('Airports')
                    ->relationship('airports', 'nameAirport')
                    ->multiple()
                    ->preload()
                    ->options(function (callable $get) {
                        $originId = $get('origin_id');

                        if (! $originId) {
                            return [];
                        }

                        $origin = Origin::find($originId);
                        $countryId = $origin->country_id;

                        return Airport::where('country_id', $countryId)
                            ->pluck('nameAirport', 'id')
                            ->toArray();
                    })
                    ->required(),

                Forms\Components\Select::make('destinations')
                    ->label('Destinations')
                    ->relationship('destinations', 'name')
                    ->multiple()
                    ->reactive()
                    ->preload()
                    ->options(function ($get) {
                        $originId = $get('origin_id');
                        if ($originId) {
                            return Destination::query()
                                ->whereHas('origins', function ($query) use ($originId) {
                                    $query->where('origin_id', $originId);
                                })
                                ->pluck('name', 'id')
                                ->toArray();
                        }

                        return [];
                    })
                    ->required(),

                Forms\Components\TextInput::make('refresh_hours')
                    ->label('Refresh Hours')
                    ->numeric()
                    ->required(),

                Forms\Components\TextInput::make('description')
                    ->label('Description')
                    ->required(),

                Forms\Components\Select::make('extra_options')
                    ->label('Extra Options')
                    ->multiple()
                    ->options([
                        'cheapest_date' => 'Cheapest Date',
                        'all_dates' => 'All Dates',
                        'cheapest_hotel' => 'Cheapest Hotel',
                        'all_hotels' => 'All Hotels',
                    ])
                    ->required()
                    ->reactive(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('origin.country')
                    ->label('Origin')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('description')
                    ->label('Description')
                    ->sortable(),
                TextColumn::make('refresh_hours')
                    ->label('Refresh Hours')
                    ->sortable(),
                TextColumn::make('extra_options')
                    ->label('Extra Options')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('generateWeekendOffers')
                    ->label('Weekend')
                    ->icon('heroicon-o-sparkles')
                    ->color('success')
                    ->action(function ($record) {
                        GenerateOffersForAdConfigs::dispatch(type: 'weekend', adConfigId: $record->id)
                            ->onQueue('weekend');

                        Notification::make()
                            ->title('Job Dispatched')
                            ->body('The weekend job has been dispatched successfully.')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('generateHolidayOffers')
                    ->label('Holiday')
                    ->icon('heroicon-o-sparkles')
                    ->color('success')
                    ->action(function ($record) {
                        GenerateOffersForAdConfigs::dispatch(type: 'holiday', adConfigId: $record->id)
                            ->onQueue('holiday');

                        Notification::make()
                            ->title('Job Dispatched')
                            ->body('The holiday job has been dispatched successfully.')
                            ->success()
                            ->send();
                    }),
                //todo: add later
                Tables\Actions\Action::make('generateEconomicOffers')
                    ->label('Economic')
                    ->icon('heroicon-o-sparkles')
                    ->color('success')
                    ->action(function ($record) {
                        GenerateOffersForAdConfigs::dispatch(type: 'economic', adConfigId: $record->id)
                            ->onQueue('economic');

                        Notification::make()
                            ->title('Job Dispatched')
                            ->body('The economic job has been dispatched successfully.')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            CSVRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdConfigs::route('/'),
            'create' => Pages\CreateAdConfig::route('/create'),
            'edit' => Pages\EditAdConfig::route('/{record}/edit'),
        ];
    }
}
