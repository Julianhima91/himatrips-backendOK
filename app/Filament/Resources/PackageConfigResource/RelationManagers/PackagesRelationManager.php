<?php

namespace App\Filament\Resources\PackageConfigResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PackagesRelationManager extends RelationManager
{
    protected static string $relationship = 'packages';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('id')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('id'),
                Tables\Columns\TextColumn::make('outboundFlight.departure'),
                Tables\Columns\TextColumn::make('inboundFlight.departure'),
                Tables\Columns\TextColumn::make('total_price'),
            ])
            ->filters([
                Tables\Filters\Filter::make('departure_date')
                    ->form([
                        Forms\Components\DatePicker::make('date')->label('Departure Date'),
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
            ->actions([
            ])
            ->bulkActions([
            ]);
    }
}
