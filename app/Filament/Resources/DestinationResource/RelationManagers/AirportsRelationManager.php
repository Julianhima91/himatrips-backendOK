<?php

namespace App\Filament\Resources\DestinationResource\RelationManagers;

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
                Tables\Actions\Action::make('redirect')
                    ->label('View')
                    ->url(fn ($record) => route('filament.admin.resources.airports.edit', $record)),
                Tables\Actions\DetachAction::make(),
            ])
            ->bulkActions([
            ])
            ->emptyStateActions([
                Tables\Actions\AssociateAction::make(),
            ]);
    }
}
