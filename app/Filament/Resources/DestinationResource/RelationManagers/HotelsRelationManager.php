<?php

namespace App\Filament\Resources\DestinationResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class HotelsRelationManager extends RelationManager
{
    protected static string $relationship = 'hotels';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Hotel Information')
                    ->schema([
                        Forms\Components\TextInput::make('hotel_id')
                            ->hiddenOn('edit')
                            ->tel()
                            ->numeric(),
                        Forms\Components\TextInput::make('name')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('address')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone')
                            ->tel()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('fax')
                            ->hiddenOn('edit')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('stars')
                            ->numeric(),
                        Forms\Components\TextInput::make('longitude')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('latitude')
                            ->maxLength(255),
                        Forms\Components\Toggle::make('is_apartment')
                            ->hiddenOn('edit'),
                        Forms\Components\TextInput::make('city')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('country')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('review_score')
                            ->numeric(),
                        Forms\Components\TextInput::make('review_count')
                            ->numeric(),
                        Forms\Components\RichEditor::make('description')
                            ->maxLength(65535)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name'),
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
            ]);
    }
}
