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
            ->headerActions([
                Tables\Actions\Action::make('generateOffers')
                    ->label('Generate Offers')
                    ->icon('heroicon-o-sparkles')
                    ->color('success')
                    ->action(function () {
                        GenerateOffersForAdConfigs::dispatch();

                        Notification::make()
                            ->title('Job Dispatched')
                            ->body('The GenerateOffersForAdConfigs job has been dispatched successfully.')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
