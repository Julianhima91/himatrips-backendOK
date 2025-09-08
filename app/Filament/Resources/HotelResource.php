<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HotelResource\Pages\CreateHotel;
use App\Filament\Resources\HotelResource\Pages\EditHotel;
use App\Filament\Resources\HotelResource\Pages\ListHotels;
use App\Filament\Resources\HotelResource\RelationManagers\DestinationsRelationManager;
use App\Filament\Resources\HotelResource\RelationManagers\FacilitiesRelationManager;
use App\Filament\Resources\HotelResource\RelationManagers\ReviewsRelationManager;
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
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HotelResource extends Resource
{
    protected static ?string $model = Hotel::class;

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
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
                        // keeping them commented in case we actually need them, and we can add the migration later on
                        //                        Forms\Components\TextInput::make('review_score')
                        //                            ->numeric(),
                        //                        Forms\Components\TextInput::make('review_count')
                        //                            ->numeric(),
                        RichEditor::make('description')
                            ->maxLength(65535)
                            ->columnSpanFull(),
                        TextInput::make('booking_url')
                            ->label('Booking URL')
                            ->url()
                            ->placeholder('https://www.booking.com/hotel/...')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Review Summary')
                    ->schema([
                        TextInput::make('reviewSummary.total_score')
                            ->label('Review Score')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('reviewSummary.review_count')
                            ->label('Total Reviews')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(2),
                Section::make('Hotel Photos')
                    ->schema([
                        FileUpload::make('Images')
                            ->image()
                            ->disk('public')
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
                Tables\Columns\TextColumn::make('reviewSummary.total_score')
                    ->label('Review Score')
                    ->badge()
                    ->color(fn ($state) => match (true) {
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
            FacilitiesRelationManager::class,
            ReviewsRelationManager::class,
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
