<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AirlineResource\Pages\CreateAirline;
use App\Filament\Resources\AirlineResource\Pages\EditAirline;
use App\Filament\Resources\AirlineResource\Pages\ListAirlines;
use App\Models\Airline;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AirlineResource extends Resource
{
    protected static ?string $model = Airline::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nameAirline')
                    ->autofocus()
                    ->required()
                    ->label('Name'),
                TextInput::make('api_id')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nameAirline')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('api_id')
                    ->label('API ID'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateActions([
                CreateAction::make(),
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
            'index' => ListAirlines::route('/'),
            'create' => CreateAirline::route('/create'),
            'edit' => EditAirline::route('/{record}/edit'),
        ];
    }
}
