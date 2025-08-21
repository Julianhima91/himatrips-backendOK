<?php

namespace App\Filament\Resources\HotelResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TransfersRelationManager extends RelationManager
{
    protected static string $relationship = 'Transfers';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('description')
                    ->required()
                    ->maxLength(255),

                TextInput::make('adult_price')
                    ->numeric()
                    ->required()
                    ->label('Adult Price'),

                Toggle::make('has_children_price')
                    ->label('Enable Children Price')
                    ->live()
                    ->default(false)
                    ->reactive()
                    ->dehydrated(false),

                TextInput::make('children_price')
                    ->numeric()
                    ->required()
                    ->label('Children Price')
                    ->visible(fn ($get) => $get('has_children_price')),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('description'),
                TextColumn::make('adult_price')->money('EUR')->label('Adult Price'),
                TextColumn::make('children_price')->money('EUR')->label('Children Price'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                AttachAction::make(),
                CreateAction::make(),
            ])
            ->recordActions([
                Action::make('redirect')
                    ->label('View')
                    ->url(fn ($record) => route('filament.admin.resources.transfers.edit', $record)),
                DetachAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
