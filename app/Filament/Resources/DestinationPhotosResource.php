<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DestinationPhotosResource\Pages;
use App\Filament\Resources\DestinationPhotosResource\Pages\EditDestinationPhotos;
use App\Filament\Resources\DestinationPhotosResource\Pages\ListDestinationPhotos;
use App\Models\DestinationPhoto;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\SpatieTagsInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DestinationPhotosResource extends Resource
{
    protected static ?string $model = DestinationPhoto::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Destination Photo')
                    ->schema([
                        FileUpload::make('file_path')
                            ->disk('public')
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
                TextColumn::make('destination.name')
                    ->searchable(),
                TextColumn::make('file_path')
                    ->label('Name'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
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
            'index' => ListDestinationPhotos::route('/'),
            //            'create' => Pages\CreateDestinationPhotos::route('/create'),
            'edit' => EditDestinationPhotos::route('/{record}/edit'),
        ];
    }
}
