<?php

namespace App\Filament\Resources\HotelResource\RelationManagers;

use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class DestinationsRelationManager extends RelationManager
{
    protected static string $relationship = 'destinations';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('city')
            ->columns([
                Tables\Columns\TextColumn::make('city'),
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
                    ->url(fn ($record) => route('filament.admin.resources.destinations.edit', $record)),
                Tables\Actions\DetachAction::make(),
            ])
            ->bulkActions([
                //                Tables\Actions\BulkActionGroup::make([
                //                    Tables\Actions\DetachBulkAction::make(),
                //                ]),
            ])
            ->emptyStateActions([
                Tables\Actions\AttachAction::make(),
            ]);
    }
}
