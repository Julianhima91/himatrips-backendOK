<?php

namespace App\Filament\Resources\DestinationResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HotelsRelationManager extends RelationManager
{
    protected static string $relationship = 'hotels';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name'),
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
                    ->url(fn ($record) => route('filament.admin.resources.hotels.edit', $record)),
                DetachAction::make(),
            ])
            ->toolbarActions([
            ]);
    }
}
