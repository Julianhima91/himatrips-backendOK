<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HotelResource\Pages;
use App\Filament\Resources\HotelResource\RelationManagers\DestinationsRelationManager;
use App\Filament\Resources\HotelResource\RelationManagers\RoomTypesRelationManager;
use App\Filament\Resources\HotelResource\RelationManagers\TransfersRelationManager;
use App\Models\Hotel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HotelResource extends Resource
{
    protected static ?string $model = Hotel::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
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
                Forms\Components\Section::make('Hotel Photos')
                    ->schema([
                        Forms\Components\FileUpload::make('Images')
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
            ->headerActions([
                Tables\Actions\Action::make('Extract Room Types')
                    ->label('Extract Room Types')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->color('primary')
                    ->action(function () {
                        \App\Jobs\ExtractRoomTypesFromHotelOffers::dispatch()->onQueue('hotel-room-types');

                        Notification::make()
                            ->success()
                            ->title('Score Job Dispatched')
                            ->body('Extracting all rooms for active hotels.')
                            ->send();
                    }),
            ])
            ->columns([
                //                Tables\Columns\TextColumn::make('destination.name')
                //                    ->label('Destination')
                //                    ->searchable()
                //                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('address')
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone')
                    ->searchable(),
                Tables\Columns\TextColumn::make('stars')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_apartment')
                    ->boolean(),
                Tables\Columns\TextColumn::make('city')
                    ->searchable(),
                Tables\Columns\TextColumn::make('country')
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('has_destination')
                    ->query(
                        fn (Builder $query) => $query->whereHas('destinations')
                    ),
                SelectFilter::make('destinations')->relationship('destinations', 'name'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateActions([
                Tables\Actions\CreateAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            DestinationsRelationManager::class,
            TransfersRelationManager::class,
            RoomTypesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHotels::route('/'),
            'create' => Pages\CreateHotel::route('/create'),
            'edit' => Pages\EditHotel::route('/{record}/edit'),
        ];
    }
}
