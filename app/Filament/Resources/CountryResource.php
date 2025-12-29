<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CountryResource\Pages\CreateCountry;
use App\Filament\Resources\CountryResource\Pages\EditCountry;
use App\Filament\Resources\CountryResource\Pages\ListCountries;
use App\Filament\Resources\CountryResource\RelationManagers\AirportsRelationManager;
use App\Filament\Resources\CountryResource\RelationManagers\DestinationsRelationManager;
use App\Filament\Resources\CountryResource\RelationManagers\HolidaysRelationManager;
use App\Filament\Resources\CountryResource\RelationManagers\OriginsRelationManager;
use App\Models\Country;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CountryResource extends Resource
{
    protected static ?string $model = Country::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Advertising';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('code')
                    ->label('Country Code (ISO 2-letter)')
                    ->maxLength(2)
                    ->helperText('ISO 2-letter country code (e.g., IT, GB, AL, FR)')
                    ->placeholder('IT')
                    ->uppercase(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('code')
                    ->label('Country Code')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
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
            ]);
    }

    public static function getRelations(): array
    {
        return [
            OriginsRelationManager::class,
            DestinationsRelationManager::class,
            AirportsRelationManager::class,
            HolidaysRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCountries::route('/'),
            'create' => CreateCountry::route('/create'),
            'edit' => EditCountry::route('/{record}/edit'),
        ];
    }
}
