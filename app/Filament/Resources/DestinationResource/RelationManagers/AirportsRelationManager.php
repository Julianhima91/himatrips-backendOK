<?php

namespace App\Filament\Resources\DestinationResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\AssociateAction;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
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
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('codeIataAirport')
            ->columns([
                TextColumn::make('codeIataAirport')
                    ->label('IATA Code'),
                TextColumn::make('nameAirport')
                    ->label('Name'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                AttachAction::make(),
            ])
            ->recordActions([
                Action::make('redirect')
                    ->label('View')
                    ->url(fn ($record) => route('filament.admin.resources.airports.edit', $record)),
                DetachAction::make(),
            ])
            ->toolbarActions([
            ])
            ->emptyStateActions([
                AssociateAction::make(),
            ]);
    }
}
