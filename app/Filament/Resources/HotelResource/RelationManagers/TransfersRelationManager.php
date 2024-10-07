<?php

namespace App\Filament\Resources\HotelResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class TransfersRelationManager extends RelationManager
{
    protected static string $relationship = 'Transfers';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('description')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('adult_price')
                    ->numeric()
                    ->required()
                    ->label('Adult Price'),

                Forms\Components\Toggle::make('has_children_price')
                    ->label('Enable Children Price')
                    ->live()
                    ->default(false)
                    ->reactive()
                    ->dehydrated(false),

                Forms\Components\TextInput::make('children_price')
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
                Tables\Columns\TextColumn::make('description'),
                Tables\Columns\TextColumn::make('adult_price')->money('EUR')->label('Adult Price'),
                Tables\Columns\TextColumn::make('children_price')->money('EUR')->label('Children Price'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make(),
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('redirect')
                    ->label('View')
                    ->url(fn ($record) => route('filament.admin.resources.transfers.edit', $record)),
                Tables\Actions\DetachAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
