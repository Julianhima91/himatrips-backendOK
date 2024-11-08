<?php

namespace App\Filament\Widgets;

use App\Models\Airport;
use App\Models\Destination;
use App\Models\Origin;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class PopularPackages extends BaseWidget
{
    protected string|int|array $columnSpan = 2;

    public $originId = null;

    public $destinationId = null;

    public function table(Table $table): Table
    {
        $destinations = Destination::query()
            ->selectRaw('
                destinations.id,
                destinations.name,
                destinations.country,
                COUNT(DISTINCT packages.batch_id) AS search_count
            ')
            ->leftJoin('destination_origins', 'destinations.id', '=', 'destination_origins.destination_id')
            ->leftJoin('origins', 'destination_origins.origin_id', '=', 'origins.id')
            ->leftJoin('package_configs', 'destination_origins.id', '=', 'package_configs.destination_origin_id')
            ->leftJoin('packages', 'packages.package_config_id', '=', 'package_configs.id')
            ->leftJoin('flight_data', 'flight_data.id', '=', 'packages.outbound_flight_id')
            ->groupBy('destinations.id')
            ->orderBy('search_count', 'desc');

        return $table
            ->query($destinations)
            ->columns([
                //                Tables\Columns\TextColumn::make('origin_name')
                //                    ->label('Origin Name')
                //                    ->toggleable()
                //                    ->sortable(),
                //                Tables\Columns\TextColumn::make('origin_country')
                //                    ->label('Origin country')
                //                    ->toggleable()
                //                    ->sortable(),
                //                Tables\Columns\TextColumn::make('origin_airport')
                //                    ->label('Origin airport')
                //                    ->toggleable()
                //                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Destination Name')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('country')
                    ->label('Destination country')
                    ->toggleable()
                    ->sortable(),
                //                Tables\Columns\TextColumn::make('destination_airport')
                //                    ->label('Destination airport')
                //                    ->toggleable()
                //                    ->sortable(),
                Tables\Columns\TextColumn::make('search_count')
                    ->label('Search Count')
                    ->toggleable()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('date_range')
                    ->label('Created At')
                    ->form([
                        DatePicker::make('start_date')->label('Start Date'),
                        DatePicker::make('end_date')->label('End Date'),
                    ])
                    ->query(function ($query, array $data) {
                        $startDate = $data['start_date'] ?? '2024-01-01';
                        $endDate = $data['end_date'] ?? now();

                        return $query->whereDate('packages.created_at', '>=', $startDate)
                            ->whereDate('packages.created_at', '<=', $endDate);
                    }),

                Tables\Filters\Filter::make('origin_country')
                    ->label('Origin Country')
                    ->form([
                        Select::make('origin_country')
                            ->label('Select Origin Country')
                            ->options(Origin::select('country')->distinct()->pluck('country', 'country'))
                            ->reactive()
                            ->searchable(),
                    ])
                    ->query(function ($query, array $data) {
                        if (! empty($data['origin_country'])) {
                            return $query->where('origins.country', $data['origin_country']);
                        }

                        return $query;
                    }),

                Tables\Filters\Filter::make('origin')
                    ->label('Origin')
                    ->form([
                        Select::make('origin_id')
                            ->label('Select Origin')
                            ->options(Origin::all()->pluck('name', 'id'))
                            ->reactive()
                            ->searchable(),
                    ])
                    ->query(function ($query, array $data) {
                        if (! empty($data['origin_id'])) {
                            $this->originId = $data['origin_id'];

                            return $query->where('destination_origins.origin_id', $data['origin_id']);
                        }

                        return $query;
                    }),

                Tables\Filters\Filter::make('airport')
                    ->label('Origin Airport')
                    ->form([
                        Select::make('airport_id')
                            ->label('Select Origin Airport')
                            ->options(function () {
                                if (! empty($this->originId)) {
                                    return Airport::where('origin_id', $this->originId)->pluck('nameAirport', 'codeIataAirport');
                                }

                                return [];
                            })
                            ->searchable()
                            ->reactive(),
                    ])
                    ->query(function ($query, array $data) {
                        if (! empty($data['airport_id'])) {
                            return $query->where('flight_data.origin', $data['airport_id']);
                        }

                        return $query;
                    }),

                Tables\Filters\Filter::make('destination_country')
                    ->label('Destination Country')
                    ->form([
                        Select::make('destination_country')
                            ->label('Select Destination Country')
                            ->options(Destination::select('country')->distinct()->pluck('country', 'country'))
                            ->reactive()
                            ->searchable(),
                    ])
                    ->query(function ($query, array $data) {
                        if (! empty($data['destination_country'])) {
                            return $query->where('destinations.country', $data['destination_country']);
                        }

                        return $query;
                    }),

                Tables\Filters\Filter::make('destination')
                    ->label('Destination')
                    ->form([
                        Select::make('destination_id')
                            ->label('Select Destination')
                            ->options(Destination::all()->pluck('name', 'id'))
                            ->reactive()
                            ->searchable(),
                    ])
                    ->query(function ($query, array $data) {
                        if (! empty($data['destination_id'])) {
                            $this->destinationId = $data['destination_id'];

                            return $query->where('destination_origins.destination_id', $data['destination_id']);
                        }

                        return $query;
                    }),
                Tables\Filters\Filter::make('destination_airport')
                    ->label('Destination Airport')
                    ->form([
                        Select::make('destination_airport_id')
                            ->label('Select Destination Airport')
                            ->options(function () {
                                if (! empty($this->originId)) {
                                    return Airport::whereHas('destinations', function ($query) {
                                        return $query->where('destination_id', $this->destinationId);
                                    })->pluck('nameAirport', 'codeIataAirport');
                                }

                                return [];
                            })
                            ->searchable()
                            ->reactive(),
                    ])
                    ->query(function ($query, array $data) {
                        if (! empty($data['destination_airport_id'])) {
                            return $query->where('flight_data.destination', $data['destination_airport_id']);
                        }

                        return $query;
                    }),
            ]);
    }
}
