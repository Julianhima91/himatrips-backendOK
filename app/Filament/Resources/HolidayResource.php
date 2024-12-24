<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HolidayResource\Pages;
use App\Models\Holiday;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class HolidayResource extends Resource
{
    protected static ?string $model = Holiday::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('origin_id')
                    ->label('Origin')
                    ->relationship('origin', 'name')
                    ->required(),
                Forms\Components\TextInput::make('name')
                    ->label('Holiday Name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('day')
                    ->label('Day (DD-MM)')
                    ->required()
                    ->maxLength(5)
                    ->placeholder('e.g., 25-12')
                    ->regex('/^(0[1-9]|[12][0-9]|3[01])-(0[1-9]|1[0-2])$/')
                    ->helperText('Enter the day in DD-MM format, e.g., 25-12 for December 25'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('origin.name')
                    ->label('Origin')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Holiday Name')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('day')
                    ->label('Date')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('origin_id')
                    ->label('Origin')
                    ->searchable()
                    ->relationship('origin', 'name')
                    ->placeholder('All Origins'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
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
            'index' => Pages\ListHolidays::route('/'),
            'create' => Pages\CreateHoliday::route('/create'),
            'edit' => Pages\EditHoliday::route('/{record}/edit'),
        ];
    }
}
