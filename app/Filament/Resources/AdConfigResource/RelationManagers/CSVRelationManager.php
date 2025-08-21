<?php

namespace App\Filament\Resources\AdConfigResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class CSVRelationManager extends RelationManager
{
    protected static string $relationship = 'csvs';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('file_path')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('file_path')
            ->columns([
                TextColumn::make('file_path')
                    ->label('CSV File')
                    ->url(fn ($record) => Storage::url('offers/'.$record->file_path)),
            ])
            ->filters([
                //
            ])
            ->headerActions([
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn ($record) => Storage::url('offers/'.$record->file_path))
                    ->openUrlInNewTab()
                    ->color('primary'),
                DeleteAction::make()
                    ->action(function ($record) {
                        $filePath = 'public/offers/'.$record->file_path;
                        if (Storage::exists($filePath)) {
                            Storage::delete($filePath);
                        }

                        $record->delete();
                    })
                    ->color('danger'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
