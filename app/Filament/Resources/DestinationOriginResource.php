<?php

namespace App\Filament\Resources;

use App\Enums\BoardOptionEnum;
use App\Filament\Resources\DestinationOriginResource\Pages\CreateDestinationOrigin;
use App\Filament\Resources\DestinationOriginResource\Pages\EditDestinationOrigin;
use App\Filament\Resources\DestinationOriginResource\Pages\ListDestinationOrigins;
use App\Models\DestinationOrigin;
use App\Models\Tag;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DestinationOriginResource extends Resource
{
    protected static ?string $model = DestinationOrigin::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('origin_id')
                    ->relationship('origin', 'name')
                    ->disabled()
                    ->required(),
                Select::make('destination_id')
                    ->relationship('destination', 'name')
                    ->disabled()
                    ->required(),
                Select::make('boarding_options')
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
                TextInput::make('min_nights')
                    ->numeric()
                    ->label('Minimum Nights')
                    ->required(),

                TextInput::make('max_nights')
                    ->numeric()
                    ->label('Maximum Nights')
                    ->required(),

                TextInput::make('stops')
                    ->numeric()
                    ->label('Stops')
                    ->required(),
                Section::make('Destination Origin Photos')
                    ->schema([
                        Repeater::make('photos')
                            ->schema([
                                FileUpload::make('file_path')
                                    ->disk('public')
                                    ->label('Image')
                                    ->image()
                                    ->required(),
                                Select::make('tags')
                                    ->label('Select Tags')
                                    ->options(Tag::all()->pluck('name', 'name'))
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
                TextColumn::make('origin.name')
                    ->searchable(),
                TextColumn::make('destination.name')
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
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
            'index' => ListDestinationOrigins::route('/'),
            'create' => CreateDestinationOrigin::route('/create'),
            'edit' => EditDestinationOrigin::route('/{record}/edit'),
        ];
    }
}
