<?php

namespace App\Filament\Resources\PackageConfigResource\RelationManagers;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

class PackagesRelationManager extends RelationManager
{
    protected static string $relationship = 'packages';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('id')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('id'),
                TextColumn::make('outboundFlight.departure'),
                TextColumn::make('inboundFlight.departure'),
                TextColumn::make('total_price'),
            ])
            ->filters([
                Filter::make('departure_date')
                    ->schema([
                        DatePicker::make('date')->label('Departure Date'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query->when(
                            $data['date'],
                            fn ($q) => $q->whereHas('outboundFlight', fn ($q) => $q->whereDate('departure', $data['date'])
                            )
                                ->orWhereHas('inboundFlight', fn ($q) => $q->whereDate('departure', $data['date'])
                                )
                        );
                    }),
            ])
            ->headerActions([
            ])
            ->recordActions([
            ])
            ->toolbarActions([
            ]);
    }
}
