<?php

namespace App\Filament\Resources\HotelResource\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FacilitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'facilities';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('facility_name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('facility_name')
            ->columns([
                TextColumn::make('facility_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('group_name')
                    ->label('Category')
                    ->searchable(),
                BadgeColumn::make('charge_mode')
                    ->colors([
                        'success' => 'FREE',
                        'warning' => 'PAID',
                        'gray' => 'UNKNOWN',
                    ]),
                BadgeColumn::make('level')
                    ->colors([
                        'primary' => 'property',
                        'info' => 'room',
                    ]),
                IconColumn::make('icon')
                    ->label('Icon'),
            ])
            ->filters([
                SelectFilter::make('charge_mode')
                    ->options([
                        'FREE' => 'Free',
                        'PAID' => 'Paid',
                        'UNKNOWN' => 'Unknown',
                    ]),
                SelectFilter::make('level')
                    ->options([
                        'property' => 'Property',
                        'room' => 'Room',
                    ]),
            ])
            ->headerActions([
                //
            ])
            ->actions([
                ViewAction::make(),
            ])
            ->bulkActions([
                //
            ])
            ->defaultSort('facility_name');
    }
}
