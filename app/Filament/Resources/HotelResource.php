<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HotelResource\Pages\CreateHotel;
use App\Filament\Resources\HotelResource\Pages\EditHotel;
use App\Filament\Resources\HotelResource\Pages\ListHotels;
use App\Filament\Resources\HotelResource\RelationManagers\DestinationsRelationManager;
use App\Filament\Resources\HotelResource\RelationManagers\TransfersRelationManager;
use App\Models\Hotel;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HotelResource extends Resource
{
    protected static ?string $model = Hotel::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Hotel Information')
                    ->schema([
                        TextInput::make('hotel_id')
                            ->hiddenOn('edit')
                            ->tel()
                            ->numeric(),
                        TextInput::make('name')
                            ->maxLength(255),
                        TextInput::make('address')
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(255),
                        TextInput::make('fax')
                            ->hiddenOn('edit')
                            ->maxLength(255),
                        TextInput::make('stars')
                            ->numeric(),
                        TextInput::make('longitude')
                            ->maxLength(255),
                        TextInput::make('latitude')
                            ->maxLength(255),
                        Toggle::make('is_apartment')
                            ->hiddenOn('edit'),
                        TextInput::make('city')
                            ->maxLength(255),
                        TextInput::make('country')
                            ->maxLength(255),
                        TextInput::make('review_score')
                            ->numeric(),
                        TextInput::make('review_count')
                            ->numeric(),
                        RichEditor::make('description')
                            ->maxLength(65535)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Hotel Photos')
                    ->schema([
                        FileUpload::make('Images')
                            ->image()
                            ->panelLayout('compact')
                            ->reorderable()
                            ->imagePreviewHeight('220')
                            ->multiple(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                //                Tables\Columns\TextColumn::make('destination.name')
                //                    ->label('Destination')
                //                    ->searchable()
                //                    ->sortable(),
                TextColumn::make('name')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('address')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('phone')
                    ->searchable(),
                TextColumn::make('stars')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_apartment')
                    ->boolean(),
                TextColumn::make('city')
                    ->searchable(),
                TextColumn::make('country')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('has_destination')
                    ->query(
                        fn (Builder $query) => $query->whereHas('destinations')
                    ),
                SelectFilter::make('destinations')->relationship('destinations', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateActions([
                CreateAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            DestinationsRelationManager::class,
            TransfersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHotels::route('/'),
            'create' => CreateHotel::route('/create'),
            'edit' => EditHotel::route('/{record}/edit'),
        ];
    }
}
