<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PackageConfigResource\Pages;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\Destination;
use App\Models\Origin;
use App\Models\PackageConfig;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PackageConfigResource extends Resource
{
    protected static ?string $model = PackageConfig::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('origin')
                    ->placeholder('Select an origin')
                    ->options(
                        Origin::all()->pluck('name', 'id')
                    )
                    ->label('Origin')
                    ->required()
                    ->searchable(),

                Select::make('origin_airports')
                    ->placeholder('Select airports')
                    ->label('Origin Airports')
                    ->options(function (Forms\Get $get) {
                        return Origin::find($get('origin'))?->airports()->get()->pluck('codeIataAirport', 'id');
                    })
                    ->multiple(),

                Select::make('destination')
                    ->live()
                    ->placeholder('Select a destination')
                    ->options(function (Forms\Get $get) {
                        return Origin::find($get('origin'))?->destinations()->get()->pluck('name', 'id');
                    })
                    ->label('Destination')
                    ->required()
                    ->searchable(),

                Select::make('destination_airports')
                    ->placeholder('Select airports')
                    ->label('Destination Airports')
                    ->options(function (Forms\Get $get) {
                        return Destination::find($get('destination'))?->airports()->get()->pluck('codeIataAirport', 'id');
                    })
                    ->multiple(),

                DatePicker::make('from_date')
                    ->label('From Date')
                    ->required(),

                DatePicker::make('to_date')
                    ->label('To Date')
                    ->required(),

                Select::make('airlines')
                    ->placeholder('Select airlines')
                    ->options(
                        Airline::all()->pluck('nameAirline', 'id')
                    )
                    ->label('Airlines')
                    ->multiple()
                    ->searchable(),

                Forms\Components\Toggle::make('is_direct_flight')
                    ->inline(false)
                    ->label('Is Direct Flight'),

                Forms\Components\Toggle::make('prioritize_morning_flights')
                    ->live()
                    ->inline(false)
                    ->label('Prioritize Morning Flights'),

                Forms\Components\Toggle::make('prioritize_evening_flights')
                    ->live()
                    ->inline(false)
                    ->label('Prioritize Evening Flights'),

                Forms\Components\TextInput::make('max_wait_time')
                    ->numeric()
                    ->label('Max Wait Time'),

                Forms\Components\TextInput::make('max_stop_count')
                    ->label('Max Stop Count')
                    ->numeric()
                    ->required()
                    ->placeholder('Max Stop Count'),

                Forms\Components\TextInput::make('max_transit_time')
                    ->label('Max Transit Time (minutes)')
                    ->numeric()
                    ->placeholder('Max Transit Time'),

                Forms\Components\TextInput::make('number_of_nights')
                    ->label('Number of Nights')
                    ->placeholder('Number of Nights separated by comma')
                    ->required(),

                Select::make('room_basis')
                    ->placeholder('Select room basis or leave empty for cheapest')
                    ->options([
                        'BB' => 'BB',
                        'HB' => 'HB',
                        'FB' => 'FB',
                        'AI' => 'AI',
                        'CB' => 'CB',
                        'RO' => 'RO',
                        'BD' => 'BD',
                    ])
                    ->label('Room Basis'),

                Forms\Components\TextInput::make('update_frequency')
                    ->label('Update Frequency')
                    ->numeric()
                    ->placeholder('Update Frequency in hours'),

                Forms\Components\TextInput::make('commission_percentage')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(50)
                    ->required()
                    ->step(0.01)
                    ->label('Commission Percentage'),

                Forms\Components\TextInput::make('commission_amount')
                    ->label('Commission Amount')
                    ->minValue(0)
                    ->numeric()
                    ->placeholder('Commission Amount')
                    ->required(),

                Forms\Components\TextInput::make('price_limit')
                    ->label('Price Limit')
                    ->numeric()
                    ->placeholder('Price Limit'),
            ]);

    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('destination_origin.origin.name')
                    ->label('Origin')
                    ->description(function (PackageConfig $record) {
                        return Airport::whereIn('id', $record->origin_airports)->get()->pluck('codeIataAirport')->join(', ');
                    })
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('destination_origin.destination.name')
                    ->label('Destination')
                    ->description(function (PackageConfig $record) {
                        return Airport::whereIn('id', $record->destination_airports)->get()->pluck('codeIataAirport')->join(', ');
                    })
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('from_date')
                    ->label('From')
                    ->date('d-m-Y'),
                Tables\Columns\TextColumn::make('to_date')
                    ->label('To')
                    ->date('d-m-Y'),
                //show the numbers of packages
                Tables\Columns\TextColumn::make('packages_count')
                    ->label('Packages')
                    ->counts('packages')
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_processed_at')
                    ->label('Last Updated At')
                    ->date('d-m-Y H:i:s')
                    ->description(function (PackageConfig $record) {
                        if ($record->last_processed_at == null) {
                            return '-';
                        }

                        return $record->last_processed_at->diffForHumans();
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Next update at')
                    ->date('d-m-Y H:i:s')
                    ->formatStateUsing(function (PackageConfig $record) {
                        if ($record->last_processed_at == null) {
                            return '-';
                        }

                        return $record->last_processed_at->addHours($record->update_frequency);
                    })
                    ->description(function (PackageConfig $record) {
                        if ($record->last_processed_at == null) {
                            return '-';
                        }

                        return $record->last_processed_at->addHours($record->update_frequency)->diffForHumans();
                    }),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->iconButton(),
                Tables\Actions\Action::make('Show Package Dates')
                    ->icon('heroicon-o-calendar')
                    ->iconButton(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateActions([
                Tables\Actions\CreateAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPackageConfigs::route('/'),
            'create' => Pages\CreatePackageConfig::route('/create'),
            'edit' => Pages\EditPackageConfig::route('/{record}/edit'),
        ];
    }
}
