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

    public function table(Table $table): Table
    {
        $destinations = Destination::query()
            ->selectRaw('destinations.id, destinations.name, destinations.country, COUNT(packages.id) AS package_count')
            ->leftJoin('destination_origins', 'destinations.id', '=', 'destination_origins.destination_id')
            ->leftJoin('package_configs', 'destination_origins.id', '=', 'package_configs.destination_origin_id')
            ->leftJoin('packages', 'packages.package_config_id', '=', 'package_configs.id')
            ->groupBy('destinations.id')
            ->orderBy('package_count', 'desc');

        return $table
            ->query($destinations)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Destination Name')
                    ->sortable(),
                Tables\Columns\TextColumn::make('country')
                    ->label('Destination Country')
                    ->sortable(),
                Tables\Columns\TextColumn::make('package_count')
                    ->label('Package Count')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('date_range')
                    ->label('Created At')
                    ->form([
                        DatePicker::make('start_date')->label('Start Date'),
                        DatePicker::make('end_date')->label('End Date'),
                    ])
                    ->query(fn ($query, array $data) => $query->whereBetween('packages.created_at', [
                        $data['start_date'] ?? now()->subYear(),
                        $data['end_date'] ?? now(),
                    ])),

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
                    ->label('Airport')
                    ->form([
                        Select::make('airport_id')
                            ->label('Select Airport')
                            ->options(function () {
                                if (! empty($this->originId)) {
                                    return Airport::where('origin_id', $this->originId)->pluck('nameAirport', 'id');
                                }

                                return [];
                            })
                            ->searchable()
                            ->reactive(),
                    ])
                    ->query(function ($query, array $data) {
                        //todo: after we implement airport id to the workflow
                        //                        if (!empty($data['airport_id'])) {
                        //                            return $query->where('destination_origins.airport_id', $data['airport_id']);
                        //                        }
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
                            $this->originId = $data['destination_id'];

                            return $query->where('destination_origins.destination_id', $data['destination_id']);
                        }

                        return $query;
                    }),
            ]);
    }
}
