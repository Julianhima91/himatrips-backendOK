<?php

namespace App\Filament\Resources\DestinationResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class AirportsRelationManager extends RelationManager
{
    protected static string $relationship = 'airports';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('nameAirport')
                    ->autofocus()
                    ->required()
                    ->label('Name'),
                Forms\Components\TextInput::make('sky_id')
                    ->required(),
                Forms\Components\TextInput::make('entity_id')
                    ->required(),
                Forms\Components\TextInput::make('codeIataAirport')
                    ->label('IATA Code')
                    ->required(),
                Forms\Components\TextInput::make('rapidapi_id')
                    ->label('Rapid API ID'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('codeIataAirport')
            ->columns([
                Tables\Columns\TextColumn::make('codeIataAirport')
                    ->label('IATA Code'),
                Tables\Columns\TextColumn::make('nameAirport')
                    ->label('Name'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make(),
            ])
            ->actions([
                Tables\Actions\DetachAction::make(),
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
            ])
            ->emptyStateActions([
                Tables\Actions\AssociateAction::make(),
            ]);
    }
}
