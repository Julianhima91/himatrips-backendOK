<?php

namespace App\Filament\Resources;

use App\Enums\ActiveMonthsEnum;
use App\Enums\BoardOptionEnum;
use App\Enums\OfferCategoryEnum;
use App\Filament\Resources\DestinationResource\Pages\CreateDestination;
use App\Filament\Resources\DestinationResource\Pages\EditDestination;
use App\Filament\Resources\DestinationResource\Pages\ListDestinations;
use App\Filament\Resources\DestinationResource\RelationManagers\AirportsRelationManager;
use App\Filament\Resources\DestinationResource\RelationManagers\HotelsRelationManager;
use App\Filament\Resources\DestinationResource\RelationManagers\OriginsRelationManager;
use App\Jobs\DestinationPackageConfigJob;
use App\Models\CommissionRule;
use App\Models\Destination;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieTagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DestinationResource extends Resource
{
    protected static ?string $model = Destination::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                SpatieTagsInput::make('tags'),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Toggle::make('show_in_homepage')
                    ->inline(false)
                    ->label('Show in homepage'),
                Toggle::make('is_active')
                    ->inline(false)
                    ->label('Is Active'),
                Select::make('offer_category')
                    ->label('Offer Categories')
                    ->multiple()
                    ->options(OfferCategoryEnum::class)
                    ->placeholder('Select offer categories')
                    ->required(),
                TextInput::make('ad_min_nights')
                    ->label('Ad Min Nights')
                    ->numeric()
                    ->minValue(0)
                    ->placeholder('Minimum nights for ads')
                    ->required(),
                TextInput::make('ad_max_nights')
                    ->label('Ad Max Nights')
                    ->numeric()
                    ->minValue(0)
                    ->placeholder('Maximum nights for ads')
                    ->required(),
                Select::make('active_months')
                    ->label('Active Months')
                    ->multiple()
                    ->options(ActiveMonthsEnum::class)
                    ->placeholder('Select active months'),
                Textarea::make('description')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                TextInput::make('city')
                    ->required()
                    ->maxLength(255),
                Select::make('country_id')
                    ->relationship('country', 'name')
                    ->required(),
                TextInput::make('address')
                    ->maxLength(255),
                TextInput::make('region')
                    ->maxLength(255),
                TextInput::make('neighborhood')
                    ->maxLength(255),
                TextInput::make('latitude')
                    ->numeric()
                    ->rule('between:-90,90')
                    ->step(0.000001),
                TextInput::make('longitude')
                    ->numeric()
                    ->rule('between:-180,180')
                    ->step(0.000001),
                Select::make('board_options')
                    ->multiple()
                    ->label('Board Options')
                    ->options(
                        collect(BoardOptionEnum::cases())
                            ->mapWithKeys(fn (BoardOptionEnum $option) => [$option->name => $option->getLabel()])
                            ->toArray()
                    )
                    ->placeholder('Select Board Options'),
                TimePicker::make('morning_flight_start_time')
                    ->live()
                    ->hidden(fn (Get $get) => ! $get('prioritize_morning_flights'))
                    ->label('Morning Flight Start Time'),
                TimePicker::make('morning_flight_end_time')
                    ->live()
                    ->hidden(fn (Get $get) => ! $get('prioritize_morning_flights'))
                    ->label('Morning Flight End Time'),
                TimePicker::make('evening_flight_start_time')
                    ->live()
                    ->hidden(fn (Get $get) => ! $get('prioritize_evening_flights'))
                    ->label('Evening Flight Start Time'),
                TimePicker::make('evening_flight_end_time')
                    ->live()
                    ->hidden(fn (Get $get) => ! $get('prioritize_evening_flights'))
                    ->label('Evening Flight End Time'),
                TextInput::make('min_nights_stay')
                    ->label('Min Nights Stay')
                    ->default(0)
                    ->numeric()
                    ->required()
                    ->placeholder('Min Nights Stay'),
                Select::make('commission_rule_id')
                    ->label('Commission Rule')
                    ->options(CommissionRule::all()->pluck('name', 'id'))
                    ->searchable()
                    ->nullable()
                    ->helperText('Select a commission rule or leave empty for default'),
                Section::make('Destination Photo')
                    ->schema([
                        FileUpload::make('Images')
                            ->maxSize(30480)
                            ->multiple()
                            ->panelLayout('square'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('city')
                    ->searchable(),
                TextColumn::make('country')
                    ->searchable(),
                IconColumn::make('destinationPhotos')
                    ->label('Has Photo')
                    ->icon(fn (Destination $destination) => $destination->destinationPhotos()->exists() ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle'),
                ToggleColumn::make('is_active')
                    ->disabled(),
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
                Filter::make('has_photo')
                    ->query(
                        fn (Builder $query) => $query->whereHas('destinationPhotos')
                    ),
                Filter::make('has_hotels')
                    ->query(
                        fn (Builder $query) => $query->whereHas('hotels')
                    ),
                Filter::make('Does_not_have_photo')
                    ->query(
                        fn (Builder $query) => $query->whereDoesntHave('origins')
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('Create Connections')
                    ->label('Create Connections')
                    ->action(function ($record) {
                        DestinationPackageConfigJob::dispatch($record->id);
                    })
                    ->color('primary')
                    ->icon('heroicon-o-bolt'),
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
            HotelsRelationManager::class,
            AirportsRelationManager::class,
            OriginsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDestinations::route('/'),
            'create' => CreateDestination::route('/create'),
            'edit' => EditDestination::route('/{record}/edit'),
        ];
    }
}
