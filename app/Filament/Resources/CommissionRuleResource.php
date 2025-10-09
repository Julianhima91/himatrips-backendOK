<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CommissionRuleResource\Pages\CreateCommissionRule;
use App\Filament\Resources\CommissionRuleResource\Pages\EditCommissionRule;
use App\Filament\Resources\CommissionRuleResource\Pages\ListCommissionRules;
use App\Models\CommissionRule;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CommissionRuleResource extends Resource
{
    protected static ?string $model = CommissionRule::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static string|null|\UnitEnum $navigationGroup = 'System';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('minimum_number')
                    ->required()
                    ->numeric()
                    ->minValue(0),

                TextInput::make('minimum_percentage')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(0.01),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->sortable()->searchable(),
                TextColumn::make('minimum_number')->sortable()->money('EUR'),
                TextColumn::make('minimum_percentage')->sortable()->formatStateUsing(fn ($state) => $state.'%'),
                TextColumn::make('created_at')->dateTime()->sortable(),
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
            'index' => ListCommissionRules::route('/'),
            'create' => CreateCommissionRule::route('/create'),
            'edit' => EditCommissionRule::route('/{record}/edit'),
        ];
    }
}
