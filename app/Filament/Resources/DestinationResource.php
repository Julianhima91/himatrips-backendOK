<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DestinationResource\Pages;
use App\Filament\Resources\DestinationResource\RelationManagers;
use App\Models\Destination;
use Filament\Forms;
use Filament\Forms\Components\SpatieTagsInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DestinationResource extends Resource
{
    protected static ?string $model = Destination::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                SpatieTagsInput::make('tags'),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Toggle::make('show_in_homepage')
                    ->inline(false)
                    ->label('Show in homepage'),
                Forms\Components\Toggle::make('is_active')
                    ->inline(false)
                    ->label('Is Active'),
                Forms\Components\Textarea::make('description')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('city')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('country_id')
                    ->relationship('country', 'name')
                    ->required(),
                Forms\Components\TextInput::make('address')
                    ->maxLength(255),
                Forms\Components\TextInput::make('region')
                    ->maxLength(255),
                Forms\Components\TextInput::make('neighborhood')
                    ->maxLength(255),
                Forms\Components\TextInput::make('latitude')
                    ->numeric()
                    ->rule('between:-90,90')
                    ->step(0.000001),
                Forms\Components\TextInput::make('longitude')
                    ->numeric()
                    ->rule('between:-180,180')
                    ->step(0.000001),
                Forms\Components\Select::make('board_options')
                    ->multiple()
                    ->label('Board Options')
                    ->options([
                        'BB' => 'Bed & Breakfast',
                        'HB' => 'Half Board',
                        'FB' => 'Full Board',
                        'AI' => 'All Inclusive',
                        'RO' => 'Room Only',
                        'CB' => 'Continental Breakfast',
                        'BD' => 'Bed & Dinner',
                    ])
                    ->placeholder('Select Board Options'),
                Forms\Components\TimePicker::make('morning_flight_start_time')
                    ->live()
                    ->hidden(fn (Get $get) => ! $get('prioritize_morning_flights'))
                    ->label('Morning Flight Start Time'),
                Forms\Components\TimePicker::make('morning_flight_end_time')
                    ->live()
                    ->hidden(fn (Get $get) => ! $get('prioritize_morning_flights'))
                    ->label('Morning Flight End Time'),
                Forms\Components\TimePicker::make('evening_flight_start_time')
                    ->live()
                    ->hidden(fn (Get $get) => ! $get('prioritize_evening_flights'))
                    ->label('Evening Flight Start Time'),
                Forms\Components\TimePicker::make('evening_flight_end_time')
                    ->live()
                    ->hidden(fn (Get $get) => ! $get('prioritize_evening_flights'))
                    ->label('Evening Flight End Time'),
                Forms\Components\TextInput::make('min_nights_stay')
                    ->label('Min Nights Stay')
                    ->default(0)
                    ->numeric()
                    ->required()
                    ->placeholder('Min Nights Stay'),
                Forms\Components\Section::make('Destination Photo')
                    ->schema([
                        Forms\Components\FileUpload::make('Images')
                            ->image()
                            ->panelLayout('square'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('city')
                    ->searchable(),
                Tables\Columns\TextColumn::make('country')
                    ->searchable(),
                Tables\Columns\IconColumn::make('destinationPhotos')
                    ->label('Has Photo')
                    ->icon(fn (Destination $destination) => $destination->destinationPhotos()->exists() ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle'),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->disabled(),
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
                Tables\Filters\Filter::make('has_photo')
                    ->query(
                        fn (Builder $query) => $query->whereHas('destinationPhotos')
                    ),
                Tables\Filters\Filter::make('has_hotels')
                    ->query(
                        fn (Builder $query) => $query->whereHas('hotels')
                    ),
                Tables\Filters\Filter::make('Does_not_have_photo')
                    ->query(
                        fn (Builder $query) => $query->whereDoesntHave('origins')
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
            RelationManagers\HotelsRelationManager::class,
            RelationManagers\AirportsRelationManager::class,
            RelationManagers\OriginsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDestinations::route('/'),
            'create' => Pages\CreateDestination::route('/create'),
            'edit' => Pages\EditDestination::route('/{record}/edit'),
        ];
    }
}
