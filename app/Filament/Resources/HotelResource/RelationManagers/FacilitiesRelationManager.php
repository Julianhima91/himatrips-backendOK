<?php

namespace App\Filament\Resources\HotelResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class FacilitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'facilities';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('facility_name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('facility_name')
            ->columns([
                Tables\Columns\TextColumn::make('facility_name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('group_name')
                    ->label('Category')
                    ->searchable(),
                Tables\Columns\BadgeColumn::make('charge_mode')
                    ->colors([
                        'success' => 'FREE',
                        'warning' => 'PAID',
                        'gray' => 'UNKNOWN',
                    ]),
                Tables\Columns\BadgeColumn::make('level')
                    ->colors([
                        'primary' => 'property',
                        'info' => 'room',
                    ]),
                Tables\Columns\IconColumn::make('icon')
                    ->label('Icon'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('charge_mode')
                    ->options([
                        'FREE' => 'Free',
                        'PAID' => 'Paid',
                        'UNKNOWN' => 'Unknown',
                    ]),
                Tables\Filters\SelectFilter::make('level')
                    ->options([
                        'property' => 'Property',
                        'room' => 'Room',
                    ]),
            ])
            ->headerActions([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                //
            ])
            ->defaultSort('facility_name');
    }
}
