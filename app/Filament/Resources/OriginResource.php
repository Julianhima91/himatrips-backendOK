<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OriginResource\Pages\CreateOrigin;
use App\Filament\Resources\OriginResource\Pages\EditOrigin;
use App\Filament\Resources\OriginResource\Pages\ListOrigins;
use App\Filament\Resources\OriginResource\RelationManagers\AirportsRelationManager;
use App\Filament\Resources\OriginResource\RelationManagers\DestinationsRelationManager;
use App\Jobs\OriginPackageConfigJob;
use App\Models\Origin;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OriginResource extends Resource
{
    protected static ?string $model = Origin::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-right';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                TextInput::make('city')
                    ->required()
                    ->maxLength(255),
                Select::make('country_id')
                    ->relationship('country', 'name')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('city')
                    ->searchable(),
                TextColumn::make('country.name')
                    ->label('Country')
                    ->searchable(),
                TextColumn::make('country.code')
                    ->label('Country Code')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('Create Connections')
                    ->label('Create Connections')
                    ->action(function ($record) {
                        OriginPackageConfigJob::dispatch($record->id);
                    })
                    ->color('primary')
                    ->icon('heroicon-o-bolt'),
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
            AirportsRelationManager::class,
            DestinationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrigins::route('/'),
            'create' => CreateOrigin::route('/create'),
            'edit' => EditOrigin::route('/{record}/edit'),
        ];
    }
}
