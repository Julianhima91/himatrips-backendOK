<?php

namespace App\Filament\Resources;

use App\Enums\BoardOptionEnum;
use App\Filament\Resources\AdConfigResource\Pages\CreateAdConfig;
use App\Filament\Resources\AdConfigResource\Pages\EditAdConfig;
use App\Filament\Resources\AdConfigResource\Pages\ListAdConfigs;
use App\Filament\Resources\AdConfigResource\RelationManagers\CSVRelationManager;
use App\Jobs\EconomicAdJob;
use App\Jobs\GenerateOffersForAdConfigs;
use App\Jobs\HolidayAdJob;
use App\Jobs\WeekendAdJob;
use App\Models\AdConfig;
use App\Models\Airport;
use App\Models\Destination;
use App\Models\Origin;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class AdConfigResource extends Resource
{
    protected static ?string $model = AdConfig::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Advertising';

    protected static ?string $navigationLabel = 'Ad Configs';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('origin_id')
                    ->label('Origin')
                    ->relationship('origin', 'name')
                    ->searchable()
                    ->reactive()
                    ->required(),

                Select::make('airports')
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

                Select::make('destinations')
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

                TextInput::make('refresh_hours')
                    ->label('Refresh Hours')
                    ->numeric()
                    ->required(),

                TextInput::make('description')
                    ->label('Description')
                    ->required(),

                Select::make('extra_options')
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

                Toggle::make('autoupdate')
                    ->label('Autoupdate')
                    ->required(),

                Select::make('boarding_options')
                    ->label('Boarding Options')
                    ->multiple()
                    ->options(
                        collect(BoardOptionEnum::cases())
                            ->mapWithKeys(fn ($case) => [$case->name => $case->getLabel()])
                            ->toArray()
                    )
                    ->searchable()
                    ->preload()
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ToggleColumn::make('autoupdate')
                    ->label('Auto Update')
                    ->disabled(),
                TextColumn::make('description')
                    ->label('Description')
                    ->sortable()
                    ->wrap(),
                TextColumn::make('refresh_hours')
                    ->label('Refresh Hours')
                    ->sortable()
                    ->suffix(' hrs'),
                TextColumn::make('extra_options')
                    ->label('Extra Options')
                    ->badge()
                    ->sortable(),
                TextColumn::make('economic_last_run')
                    ->label('Economic Last Run')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('holiday_last_run')
                    ->label('Holiday Last Run')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('weekend_last_run')
                    ->label('Weekend Last Run')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([])
            ->recordActions([
                EditAction::make(),
                Action::make('generateWeekendOffers')
                    ->label('Weekend')
                    ->icon('heroicon-o-sparkles')
                    ->color('success')
                    ->action(function ($record) {
                        //                        Old Version 1.0
                        //                        GenerateOffersForAdConfigs::dispatch(type: 'weekend', adConfigId: $record->id)
                        //                            ->onQueue('weekend');

                        WeekendAdJob::dispatch(adConfigId: $record->id)
                            ->onQueue('weekend');

                        Notification::make()
                            ->title('Job Dispatched')
                            ->body('The weekend job has been dispatched successfully.')
                            ->success()
                            ->send();
                    }),
                Action::make('generateHolidayOffers')
                    ->label('Holiday')
                    ->icon('heroicon-o-sparkles')
                    ->color('success')
                    ->action(function ($record) {
                        //                        Old Version 1.0
                        //                        GenerateOffersForAdConfigs::dispatch(type: 'holiday', adConfigId: $record->id)
                        //                            ->onQueue('holiday');

                        HolidayAdJob::dispatch(adConfigId: $record->id)
                            ->onQueue('holiday');

                        Notification::make()
                            ->title('Job Dispatched')
                            ->body('The holiday job has been dispatched successfully.')
                            ->success()
                            ->send();
                    }),
                // todo: add later
                Action::make('generateEconomicOffers')
                    ->label('Economic')
                    ->icon('heroicon-o-sparkles')
                    ->color('success')
                    ->action(function ($record) {
                        EconomicAdJob::dispatch(adConfigId: $record->id)
                            ->onQueue('economic');

                        Notification::make()
                            ->title('Job Dispatched')
                            ->body('The economic job has been dispatched successfully.')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
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
            'index' => ListAdConfigs::route('/'),
            'create' => CreateAdConfig::route('/create'),
            'edit' => EditAdConfig::route('/{record}/edit'),
        ];
    }
}
