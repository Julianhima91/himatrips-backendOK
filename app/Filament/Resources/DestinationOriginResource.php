<?php

namespace App\Filament\Resources;

use App\Enums\BoardOptionEnum;
use App\Filament\Resources\DestinationOriginResource\Pages;
use App\Models\DestinationOrigin;
use App\Models\Tag;
use Filament\Forms;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DestinationOriginResource extends Resource
{
    protected static ?string $model = DestinationOrigin::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('origin_id')
                    ->relationship('origin', 'name')
                    ->disabled()
                    ->required(),
                Forms\Components\Select::make('destination_id')
                    ->relationship('destination', 'name')
                    ->disabled()
                    ->required(),
                Forms\Components\Select::make('boarding_options')
                    ->label('Boarding Options')
                    ->multiple()
                    ->options(
                        collect(BoardOptionEnum::cases())
                            ->mapWithKeys(fn ($case) => [$case->name => $case->getLabel()])
                            ->toArray()
                    )
                    ->searchable()
                    ->preload()
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('min_nights')
                    ->numeric()
                    ->label('Minimum Nights')
                    ->required(),

                Forms\Components\TextInput::make('max_nights')
                    ->numeric()
                    ->label('Maximum Nights')
                    ->required(),

                Forms\Components\TextInput::make('stops')
                    ->numeric()
                    ->label('Stops')
                    ->required(),
                Forms\Components\Section::make('Destination Origin Photos')
                    ->schema([
                        Repeater::make('photos')
                            ->schema([
                                FileUpload::make('file_path')
                                    ->label('Image')
                                    ->image()
                                    ->required(),
                                Select::make('tags')
                                    ->label('Select Tags')
                                    ->options(Tag::all()->pluck('name', 'id'))
                                    ->multiple()
                                    ->searchable()
                                    ->required(),
                            ])
                            ->defaultItems(1)
                            ->reorderable()
                            ->collapsible()
                            ->addActionLabel('Add Photo'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('origin.name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('destination.name')
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            'index' => Pages\ListDestinationOrigins::route('/'),
            'create' => Pages\CreateDestinationOrigin::route('/create'),
            'edit' => Pages\EditDestinationOrigin::route('/{record}/edit'),
        ];
    }
}
