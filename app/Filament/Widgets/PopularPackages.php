<?php

namespace App\Filament\Widgets;

use App\Models\Airport;
use App\Models\Destination;
use App\Models\Origin;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\DB;

class PopularPackages extends BaseWidget
{
    protected string|int|array $columnSpan = 2;

    public $originId = null;
    public $destinationId = null;

    public function table(Table $table): Table
    {
        $subQuery = Destination::query()
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
            ->leftJoin('countries', 'destinations.country_id', '=', 'countries.id')
            ->groupBy('destinations.id', 'destinations.name', 'destinations.country');

        return $table
            ->query(fn () => $subQuery)
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
                Filter::make('date_range')
                    ->label('Created At')
                    ->form([
                        DatePicker::make('start_date')->label('Start Date'),
                        DatePicker::make('end_date')->label('End Date'),
                    ])
                    ->query(function ($query, array $data) {
                        $startDate = $data['start_date'] ?? now()->subMonths(3)->toDateString();
                        $endDate = $data['end_date'] ?? now();

                        return $query->whereBetween(DB::raw('DATE(packages.created_at)'), [$startDate, $endDate]);
                    }),
            ]);
    }
}
