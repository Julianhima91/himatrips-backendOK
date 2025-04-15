<?php

namespace App\Filament\Resources\AdConfigResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class CSVRelationManager extends RelationManager
{
    protected static string $relationship = 'csvs';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('file_path')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('file_path')
            ->columns([
                Tables\Columns\TextColumn::make('file_path')
                    ->label('CSV File')
                    ->url(fn ($record) => Storage::url('offers/'.$record->file_path)),
            ])
            ->filters([
                //
            ])
            ->headerActions([
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn ($record) => Storage::url('offers/'.$record->file_path))
                    ->openUrlInNewTab()
                    ->color('primary'),
                Tables\Actions\DeleteAction::make()
                    ->action(function ($record) {
                        $filePath = 'public/offers/'.$record->file_path;
                        if (Storage::exists($filePath)) {
                            Storage::delete($filePath);
                        }

                        $record->delete();
                    })
                    ->color('danger'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
