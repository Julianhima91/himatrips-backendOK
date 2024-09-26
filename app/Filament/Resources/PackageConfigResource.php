<?php

namespace App\Filament\Resources;

use App\Actions\CheckFlightAvailability;
use App\Filament\Resources\PackageConfigResource\Pages;
use App\Http\Requests\CheckFlightAvailabilityRequest;
use App\Models\Airline;
use App\Models\Origin;
use App\Models\PackageConfig;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
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

                Select::make('destination')
                    ->live()
                    ->placeholder('Select a destination')
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

                Forms\Components\Toggle::make('is_direct_flight')
                    ->inline(false)
                    ->label('Is Direct Flight'),

                Forms\Components\Toggle::make('prioritize_morning_flights')
                    ->live()
                    ->inline(false)
                    ->label('Prioritize Morning Flights'),

                Forms\Components\Toggle::make('prioritize_evening_flights')
                    ->live()
                    ->inline(false)
                    ->label('Prioritize Evening Flights'),

                Forms\Components\TextInput::make('max_wait_time')
                    ->numeric()
                    ->default(0)
                    ->label('Max Wait Time'),

                Forms\Components\TextInput::make('max_stop_count')
                    ->label('Max Stop Count')
                    ->numeric()
                    ->required()
                    ->placeholder('Max Stop Count'),

                Forms\Components\TextInput::make('max_transit_time')
                    ->label('Max Transit Time (minutes)')
                    ->default(0)
                    ->numeric()
                    ->placeholder('Max Transit Time'),

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
                    ->minValue(0)
                    ->maxValue(50)
                    ->required()
                    ->step(0.01)
                    ->label('Commission Percentage'),

                Forms\Components\TextInput::make('commission_amount')
                    ->label('Commission Amount')
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
                //show the numbers of packages
                Tables\Columns\TextColumn::make('packages_count')
                    ->label('Packages')
                    ->counts('packages')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->iconButton(),
                Tables\Actions\Action::make('Choose Package Config')
                    ->label('Check Flights')
                    ->form([
                        Forms\Components\DatePicker::make('from_date')
                            ->required()
                            ->label('Start Date'),
                        Forms\Components\DatePicker::make('to_date')
                            ->required()
                            ->label('To Date'),

                        Forms\Components\Toggle::make('is_direct_flight')
                            ->required()
                            ->label('Direct Flight'),
                        //                        Forms\Components\Select::make('airline_id')->label('Airline')
                        //                            ->options(function () {
                        //                            })
                    ])
                    ->action(function ($record, array $data) {
                        try {
                            $validator = Validator::make(array_merge($data, [
                                'package_config_id' => $record->id,
                            ]), [
                                'package_config_id' => 'required|exists:package_configs,id',
                                'from_date' => 'required|date',
                                'to_date' => 'required|date|after_or_equal:from_date',
                                'airline_id' => 'nullable|exists:airlines,id',
                                'is_direct_flight' => 'nullable|boolean',
                            ]);

                            $validatedData = $validator->validate();

                            $request = new CheckFlightAvailabilityRequest($validatedData);
                            $result = (new CheckFlightAvailability)->handle($request);

                            if ($result) {
                                Notification::make()
                                    ->success()
                                    ->title('Flight Availability Checked')
                                    ->send();
                            } else {
                                Notification::make()
                                    ->danger()
                                    ->title('No Flight Availability Found')
                                    ->send();
                            }
                        } catch (ValidationException $e) {
                            Notification::make()
                                ->danger()
                                ->title('Validation Failed')
                                ->body(implode("\n", $e->errors()))
                                ->send();

                            return;
                        }
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
            //
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
