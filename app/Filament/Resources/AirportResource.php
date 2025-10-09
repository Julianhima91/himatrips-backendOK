<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AirportResource\Pages\CreateAirport;
use App\Filament\Resources\AirportResource\Pages\EditAirport;
use App\Filament\Resources\AirportResource\Pages\ListAirports;
use App\Models\Airport;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AirportResource extends Resource
{
    protected static ?string $model = Airport::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nameAirport')
                    ->autofocus()
                    ->required()
                    ->label('Name'),
                TextInput::make('sky_id')
                    ->required(),
                TextInput::make('entity_id')
                    ->required(),
                TextInput::make('codeIataAirport')
                    ->label('IATA Code')
                    ->required(),
                TextInput::make('rapidapi_id')
                    ->label('Rapid API ID'),
                Select::make('country_id')
                    ->relationship('country', 'name')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nameAirport')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('country.name')
                    ->label('Country')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sky_id')
                    ->label('Sky ID'),
                TextColumn::make('entity_id')
                    ->label('Entity ID'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateActions([
                CreateAction::make(),
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
            'index' => ListAirports::route('/'),
            'create' => CreateAirport::route('/create'),
            'edit' => EditAirport::route('/{record}/edit'),
        ];
    }
}
