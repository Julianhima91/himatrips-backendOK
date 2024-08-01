<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DestinationResource\Pages;
use App\Filament\Resources\DestinationResource\RelationManagers;
use App\Models\Destination;
use Filament\Forms;
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
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Toggle::make('show_in_homepage')
                    ->inline(false)
                    ->label('Show in homepage'),
                Forms\Components\Textarea::make('description')
                    ->required()
                    ->maxLength(65535)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('city')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('country')
                    ->required()
                    ->maxLength(255),
                //add fields
                Forms\Components\Toggle::make('is_direct_flight')
                    ->inline(false)
                    ->label('Is Direct Flight'),
                Forms\Components\TextInput::make('commission_percentage')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(1)
                    ->step(0.01)
                    ->label('Commission Percentage'),
                Forms\Components\Toggle::make('prioritize_morning_flights')
                    ->live()
                    ->inline(false)
                    ->label('Prioritize Morning Flights'),
                Forms\Components\Toggle::make('prioritize_evening_flights')
                    ->live()
                    ->inline(false)
                    ->label('Prioritize Evening Flights'),
                Forms\Components\TextInput::make('max_stop_count')
                    ->numeric()
                    ->label('Max Stop Count'),
                Forms\Components\TextInput::make('max_wait_time')
                    ->numeric()
                    ->label('Max Wait Time'),
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
                Forms\Components\Section::make('Destination Photo')
                    ->schema([
                        Forms\Components\FileUpload::make('Images')
                            ->image()
                            ->panelLayout('square'),
                    ]),
                Forms\Components\Section::make('Hotels')->schema([
                    Forms\Components\Select::make('hotels')
                        ->relationship('hotels', 'name')
                        ->multiple(),
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
