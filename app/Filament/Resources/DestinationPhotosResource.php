<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DestinationPhotosResource\Pages;
use App\Models\DestinationPhoto;
use Filament\Forms;
use Filament\Forms\Components\SpatieTagsInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DestinationPhotosResource extends Resource
{
    protected static ?string $model = DestinationPhoto::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Destination Photo')
                    ->schema([
                        Forms\Components\FileUpload::make('file_path')
                            ->disabled()
                            ->panelLayout('square'),
                    ]),
                SpatieTagsInput::make('tags'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('destination.name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('file_path')
                    ->label('Name'),
            ])
            ->filters([
                //
            ])
            ->actions([
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDestinationPhotos::route('/'),
            //            'create' => Pages\CreateDestinationPhotos::route('/create'),
            'edit' => Pages\EditDestinationPhotos::route('/{record}/edit'),
        ];
    }
}
