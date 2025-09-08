<?php

namespace App\Filament\Resources\CountryResource\RelationManagers;

use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AirportsRelationManager extends RelationManager
{
    protected static string $relationship = 'airports';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nameAirport')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('nameAirport')
            ->columns([
                TextColumn::make('nameAirport'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
            ])
            ->recordActions([
            ])
            ->toolbarActions([
            ]);
    }
}
