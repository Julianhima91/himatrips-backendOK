<?php

namespace App\Filament\Widgets;

use App\Models\Airport;
use App\Models\Country;
use App\Models\Destination;
use App\Models\Origin;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PopularPackages extends BaseWidget
{
    protected string|int|array $columnSpan = 2;

    public $originId = null;

    public $destinationId = null;

    public function table(Table $table): Table
    {
        $destinations = Destination::query()
            ->select('destinations.id', 'destinations.name', 'countries.name as country', DB::raw('COALESCE(SUM(psc.batch_count),0) as search_count'))
            ->leftJoin('destination_origins', 'destinations.id', '=', 'destination_origins.destination_id')
            ->leftJoin('package_configs', 'destination_origins.id', '=', 'package_configs.destination_origin_id')
            ->leftJoin('package_search_counts as psc', 'psc.package_config_id', '=', 'package_configs.id')
            ->leftJoin('countries', 'destinations.country_id', '=', 'countries.id')
            ->groupBy('destinations.id', 'destinations.name', 'countries.name')
            ->orderByDesc('search_count');

        return $table
            ->query($destinations)
            ->columns([
                TextColumn::make('name')
                    ->label('Destination Name')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('country')
                    ->label('Destination country')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('search_count')
                    ->label('Search Count')
                    ->toggleable()
                    ->sortable(),
            ])
            ->filters([
                Filter::make('origin_country')
                    ->label('Origin Country')
                    ->schema([
                        Select::make('country')
                            ->label('Select Country')
                            ->options(fn () => Country::query()->pluck('name', 'id'))
                            ->searchable(),
                    ])
                    ->query(function ($query, array $data) {
                        if (! empty($data['origin_country'])) {
                            $query->where('countries.id', $data['origin_country']);
                        }

                        return $query;
                    }),
                Filter::make('origin')
                    ->label('Origin')
                    ->schema([
                        Select::make('origin_id')
                            ->label('From Origin')
                            ->options(fn () => Cache::remember('origins-list', 3600, fn () => Origin::pluck('name', 'id')))
                            ->reactive()
                            ->searchable(),
                    ])
                    ->query(function ($query, array $data) {
                        if (! empty($data['origin_id'])) {
                            $this->originId = $data['origin_id'];
                            $query->where('destination_origins.origin_id', $data['origin_id']);
                        }

                        return $query;
                    }),
                Filter::make('destination')
                    ->label('Destination')
                    ->schema([
                        Select::make('destination_id')
                            ->label('Select Destination')
                            ->options(fn () => Cache::remember('destinations-list', 3600, fn () => Destination::pluck('name', 'id')))
                            ->reactive()
                            ->searchable(),
                    ])
                    ->query(function ($query, array $data) {
                        if (! empty($data['destination_id'])) {
                            $this->destinationId = $data['destination_id'];
                            $query->where('destination_origins.destination_id', $data['destination_id']);
                        }

                        return $query;
                    }),
                Filter::make('date_range')
                    ->label('Recorded At')
                    ->schema([
                        DatePicker::make('start_date')
                            ->hint('Filtering may take a few seconds.')
                            ->label('Start Date'),
                        DatePicker::make('end_date')
                            ->hint('Filtering may take a few seconds.')
                            ->label('End Date'),
                    ])
                    ->query(function ($query, array $data) {
                        if (! empty($data['start_date'])) {
                            $endDate = $data['end_date'] ?? now()->toDateString();
                            $query->whereBetween('psc.created_at', [$data['start_date'], $endDate]);
                        }

                        return $query;
                    }),
                //                Filter::make('airport')
                //                    ->label('Origin Airport')
                //                    ->schema([
                //                        Select::make('airport_id')
                //                            ->label('Select Origin Airport')
                //                            ->options(function () {
                //                                if (! empty($this->originId)) {
                //                                    return Airport::where('origin_id', $this->originId)->pluck('nameAirport', 'codeIataAirport');
                //                                }
                //
                //                                return [];
                //                            })
                //                            ->searchable()
                //                            ->reactive(),
                //                    ])
                //                    ->query(function ($query, array $data) {
                //                        if (! empty($data['airport_id'])) {
                //                            return $query->where('flight_data.origin', $data['airport_id']);
                //                        }
                //
                //                        return $query;
                //                    }),
            ]);
    }
}
