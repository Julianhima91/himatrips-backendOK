<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HotelResource\Pages;
use App\Filament\Resources\HotelResource\RelationManagers\DestinationsRelationManager;
use App\Filament\Resources\HotelResource\RelationManagers\FacilitiesRelationManager;
use App\Filament\Resources\HotelResource\RelationManagers\ReviewsRelationManager;
use App\Filament\Resources\HotelResource\RelationManagers\TransfersRelationManager;
use App\Models\Hotel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
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
                        Forms\Components\TextInput::make('booking_url')
                            ->label('Booking URL')
                            ->url()
                            ->placeholder('https://www.booking.com/hotel/...')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Review Summary')
                    ->schema([
                        Forms\Components\TextInput::make('reviewSummary.total_score')
                            ->label('Review Score')
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('reviewSummary.review_count')
                            ->label('Total Reviews')
                            ->disabled()
                            ->dehydrated(false),
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
                Tables\Columns\TextColumn::make('reviewSummary.total_score')
                    ->label('Review Score')
                    ->badge()
                    ->color(fn ($state) => match(true) {
                        $state >= 9 => 'success',
                        $state >= 7 => 'info',
                        $state >= 5 => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('facilities_count')
                    ->label('Facilities')
                    ->counts('facilities')
                    ->badge(),
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
                TernaryFilter::make('has_booking_url')
                    ->label('Has Booking URL')
                    ->placeholder('All Hotels')
                    ->trueLabel('With Booking URL')
                    ->falseLabel('Without Booking URL')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('booking_url'),
                        false: fn (Builder $query) => $query->whereNull('booking_url'),
                        blank: fn (Builder $query) => $query,
                    ),
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
            FacilitiesRelationManager::class,
            ReviewsRelationManager::class,
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
