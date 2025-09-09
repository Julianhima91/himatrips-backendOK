<?php

namespace App\Filament\Widgets;

use App\Models\FlightPassengerStat;
use Filament\Forms\Components\TextInput;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class PassengerStatsWidget extends TableWidget
{
    protected static ?string $heading = 'Flights by Adults & Children';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $query = FlightPassengerStat::query()
            ->select('id', 'adults', 'children', 'total_flights')
            ->orderByDesc('total_flights');

        return $table
            ->query($query)
            ->columns([
                TextColumn::make('adults')
                    ->label('Adults')
                    ->sortable(),
                TextColumn::make('children')
                    ->label('Children')
                    ->sortable(),
                TextColumn::make('total_flights')
                    ->label('Total Itineraries')
                    ->formatStateUsing(fn ($record) => intval($record->total_flights))
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('Specific Adults & Children')
                    ->form([
                        TextInput::make('adults'),
                        TextInput::make('children'),
                    ])
                    ->query(function ($query, array $data) {
                        $query->when($data['adults'], fn ($query, $value) => $query->where('adults', intval($value)));
                        $query->when(isset($data['children']), fn ($query) => $query->where('children', intval($data['children'])));
                    }),
            ])
            ->defaultSort('total_flights', 'desc');
    }
}
