<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TransferResource\Pages\CreateTransfer;
use App\Filament\Resources\TransferResource\Pages\EditTransfer;
use App\Filament\Resources\TransferResource\Pages\ListTransfers;
use App\Models\Transfer;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TransferResource extends Resource
{
    protected static ?string $model = Transfer::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('description')
                    ->required()
                    ->maxLength(255),
                TextInput::make('adult_price')
                    ->numeric()
                    ->required(),
                Toggle::make('has_children_price')
                    ->label('Enable Children Price')
                    ->live()
                    ->default(false)
                    ->dehydrated(false),

                TextInput::make('children_price')
                    ->numeric()
                    ->required()
                    ->visible(fn ($get) => $get('has_children_price')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('description'),
                TextColumn::make('adult_price')->money('EUR'),
                TextColumn::make('children_price')->money('EUR'),
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
            'index' => ListTransfers::route('/'),
            'create' => CreateTransfer::route('/create'),
            'edit' => EditTransfer::route('/{record}/edit'),
        ];
    }
}
