<?php

namespace App\Filament\Resources;

use App\Actions\CheckFlightAvailability;
use App\Filament\Resources\PackageConfigResource\Pages;
use App\Filament\Resources\PackageConfigResource\RelationManagers\DirectFlightAvailabilityRelationManager;
use App\Http\Requests\CheckFlightAvailabilityRequest;
use App\Jobs\ImportPackagesJob;
use App\Models\Airline;
use App\Models\DirectFlightAvailability;
use App\Models\Origin;
use App\Models\PackageConfig;
use Coolsam\FilamentFlatpickr\Enums\FlatpickrMode;
use Coolsam\FilamentFlatpickr\Forms\Components\Flatpickr;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PackageConfigResource extends Resource
{
    protected static ?string $model = PackageConfig::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('origin')
                    ->placeholder('Select an origin')
                    ->options(
                        Origin::all()->pluck('name', 'id')
                    )
                    ->label('Origin')
                    ->required()
                    ->searchable(),

                Select::make('destination_create')
                    ->live()
                    ->multiple()
                    ->placeholder('Select a destination')
                    ->hiddenOn('edit')
                    ->options(function (Forms\Get $get) {
                        $origin = Origin::find($get('origin'));
                        $destinations = $origin?->destinations()->get()->pluck('name', 'id');

                        $array = [];
                        if ($destinations) {
                            foreach ($destinations as $key => $value) {
                                $originDestinations = DB::table('destination_origins')
                                    ->where([
                                        ['origin_id', '=', $origin->id],
                                        ['destination_id', '=', $key],
                                    ])->first();

                                if ($originDestinations) {
                                    $exists = \App\Models\PackageConfig::where('destination_origin_id', $originDestinations->id)->exists();

                                    if (! $exists) {
                                        $array[$key] = $value;
                                    }
                                }
                            }
                        }

                        return $array;
                    })
                    ->label('Destination')
                    ->required()
                    ->searchable(),

                Select::make('destination')
                    ->live()
                    ->placeholder('Select a destination')
                    ->hiddenOn('create')
                    ->options(function (Forms\Get $get) {
                        return Origin::find($get('origin'))?->destinations()->get()->pluck('name', 'id');
                    })
                    ->label('Destination')
                    ->required()
                    ->searchable(),

                Select::make('airlines')
                    ->placeholder('Select airlines')
                    ->options(
                        Airline::all()->pluck('nameAirline', 'id')
                    )
                    ->label('Airlines')
                    ->multiple()
                    ->searchable(),

                Forms\Components\Toggle::make('is_active')
                    ->inline(false)
                    ->default(true)
                    ->label('Is Active'),

                Forms\Components\Toggle::make('is_manual')
                    ->inline(false)
                    ->default(true)
                    ->label('Is Manual'),

                Forms\Components\Toggle::make('is_direct_flight')
                    ->inline(false)
                    ->label('Is Direct Flight'),

                Forms\Components\Toggle::make('prioritize_morning_flights')
                    ->live()
                    ->default(true)
                    ->inline(false)
                    ->label('Prioritize Morning Flights'),

                Forms\Components\Toggle::make('prioritize_evening_flights')
                    ->live()
                    ->default(true)
                    ->inline(false)
                    ->label('Prioritize Evening Flights'),

                Forms\Components\TextInput::make('max_wait_time')
                    ->numeric()
                    ->default(0)
                    ->label('Max Wait Time'),

                Forms\Components\TextInput::make('max_stop_count')
                    ->label('Max Stop Count')
                    ->default(1)
                    ->numeric()
                    ->required()
                    ->placeholder('Max Stop Count'),

                Select::make('room_basis')
                    ->placeholder('Select room basis or leave empty for cheapest')
                    ->options([
                        'BB' => 'BB',
                        'HB' => 'HB',
                        'FB' => 'FB',
                        'AI' => 'AI',
                        'CB' => 'CB',
                        'RO' => 'RO',
                        'BD' => 'BD',
                    ])
                    ->label('Room Basis'),

                Forms\Components\TextInput::make('commission_percentage')
                    ->numeric()
                    ->default(30)
                    ->minValue(0)
                    ->maxValue(50)
                    ->required()
                    ->step(0.01)
                    ->label('Commission Percentage'),

                Forms\Components\TextInput::make('commission_amount')
                    ->label('Commission Amount')
                    ->default(80)
                    ->minValue(0)
                    ->numeric()
                    ->placeholder('Commission Amount')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('destination_origin.origin.name')
                    ->label('Origin')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('destination_origin.destination.name')
                    ->label('Destination')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('destination_origin.directFlightsAvailability.date')
                    ->label('Start Date')
                    ->sortable()
                    ->default('-')
                    ->formatStateUsing(function ($state, $record) {
                        $earliestDate = $record->destination_origin->directFlightsAvailability()
                            ->where('is_return_flight', 0)
                            ->orderBy('date')
                            ->first()?->date;

                        return $earliestDate ?? '-';
                    })->alignCenter(),

                Tables\Columns\TextColumn::make('destination_origin.directFlightsAvailability')
                    ->label('End Date')
                    ->sortable()
                    ->default('-')
                    ->formatStateUsing(function ($state, $record) {
                        $latestDate = $record->destination_origin->directFlightsAvailability()
                            ->where('is_return_flight', 0)
                            ->orderBy('date', 'desc')
                            ->first()?->date;

                        return $latestDate ?? '-';
                    })->alignCenter(),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->disabled(),

                //show the numbers of packages
                //                Tables\Columns\TextColumn::make('packages_count')
                //                    ->label('Packages')
                //                    ->counts('packages')
                //                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                //                Tables\Actions\Action::make('Choose Package Config')
                //                    ->label('Check Flights')
                //                    ->form([
                //                        Forms\Components\DatePicker::make('from_date')
                //                            ->required()
                //                            ->label('Start Date'),
                //                        Forms\Components\DatePicker::make('to_date')
                //                            ->required()
                //                            ->label('To Date'),
                //
                //                        Forms\Components\Toggle::make('is_direct_flight')
                //                            ->default(true)
                //                            ->disabled()
                //                            ->label('Direct Flight'),
                //                        Forms\Components\Select::make('airline_id')->label('Airline')
                //                            ->getSearchResultsUsing(function (string $search) {
                //                                return \App\Models\Airline::where('nameAirline', 'like', "%{$search}%")
                //                                    ->limit(50)
                //                                    ->pluck('nameAirline', 'id');
                //                            })
                //                            ->searchable()
                //                            ->hint('Start typing to search for an airline'),
                //                    ])
                //                    ->action(function ($record, array $data) {
                //                        try {
                //                            $validator = Validator::make(array_merge($data, [
                //                                'package_config_id' => $record->id,
                //                            ]), [
                //                                'package_config_id' => 'required|exists:package_configs,id',
                //                                'from_date' => 'required|date',
                //                                'to_date' => 'required|date|after_or_equal:from_date',
                //                                'airline_id' => 'nullable|exists:airlines,id',
                //                                'is_direct_flight' => 'nullable|boolean',
                //                            ]);
                //
                //                            $validatedData = $validator->validate();
                //
                //                            $request = new CheckFlightAvailabilityRequest($validatedData);
                //                            $result = (new CheckFlightAvailability)->handle($request);
                //
                //                            if ($result) {
                //                                Notification::make()
                //                                    ->success()
                //                                    ->title('Flight Availability is being updated!')
                //                                    ->send();
                //                            } else {
                //                                Notification::make()
                //                                    ->danger()
                //                                    ->title('Something went wrong')
                //                                    ->send();
                //                            }
                //                        } catch (ValidationException $e) {
                //                            Notification::make()
                //                                ->danger()
                //                                ->title('Validation Failed')
                //                                ->body(implode("\n", $e->errors()))
                //                                ->send();
                //
                //                            return;
                //                        }
                //                    }),
                Tables\Actions\Action::make('importPackages')
                    ->label('Import Packages')
                    ->icon('heroicon-o-sparkles')
                    ->color('success')
                    ->form([
                        \Filament\Forms\Components\FileUpload::make('csv_file')
                            ->label('CSV File')
                            ->required()
                            ->acceptedFileTypes(['text/csv']),
                    ])
                    ->action(function (array $data, $record) {
                        $file = $data['csv_file'];

                        if ($file instanceof \Illuminate\Http\UploadedFile) {
                            $path = $file->storeAs('imports', 'package_'.$record->id.'.csv', 'public');
                        } else {
                            $path = $file;
                        }

                        ImportPackagesJob::dispatch($record->id, $path);

                        Notification::make()
                            ->title('Job Dispatched')
                            ->body('Packages were imported successfully.')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('insertDates')
                    ->label('Insert Dates')
                    ->form([
                        Flatpickr::make('dates')
                            ->label('Dates')
                            ->minDate('today')
                            ->maxDate(now()->addYear())
                            ->mode(FlatpickrMode::MULTIPLE),
                        Toggle::make('is_return_flight')
                            ->label('Return Dates'),
                    ])
                    ->action(function ($record, array $data) {
                        $dates = explode(',', $data['dates']);

                        foreach ($dates as $date) {
                            DirectFlightAvailability::updateOrCreate(
                                [
                                    'date' => $date,
                                    'destination_origin_id' => $record->destination_origin_id,
                                    'is_return_flight' => $data['is_return_flight'],
                                ],
                            );
                        }

                        Notification::make()
                            ->success()
                            ->title('Dates added!')
                            ->send();
                    }),
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
            DirectFlightAvailabilityRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPackageConfigs::route('/'),
            'create' => Pages\CreatePackageConfig::route('/create'),
            'edit' => Pages\EditPackageConfig::route('/{record}/edit'),
        ];
    }
}
