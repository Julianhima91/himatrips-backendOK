<?php

namespace App\Filament\Resources\CountryResource\RelationManagers;

use App\Models\Holiday;
use Filament\Forms;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class HolidaysRelationManager extends RelationManager
{
    protected static string $relationship = 'holidays';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
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

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('day'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
                Tables\Actions\Action::make('Import')
                    ->modalHeading('Import Data via CSV')
                    ->form([
                        FileUpload::make('file')
                            ->label('CSV File')
                            ->required()
                            ->acceptedFileTypes(['text/csv', 'application/csv'])
                            ->helperText('Upload your CSV file with holidays.'),
                    ])
                    ->action(function (array $data) {
                        $fileName = $data['file'];
                        $countryId = $this->ownerRecord->id;

                        $filePath = storage_path('app/public/'.$fileName);
                        $csvData = array_map('str_getcsv', file($filePath));

                        foreach (array_slice($csvData, 1) as $row) {
                            if (! empty($row[0])) {
                                Validator::make(
                                    ['name' => $row[0], 'day' => $row[1]],
                                    [
                                        'name' => 'required|string',
                                        'day' => ['required', 'regex:/^(0[1-9]|[12][0-9]|3[01])-(0[1-9]|1[0-2])$/'],
                                    ]
                                )->validate();

                                Holiday::create([
                                    'name' => $row[0],
                                    'day' => $row[1],
                                    'country_id' => $countryId,
                                ]);
                            }
                        }

                        Storage::delete('public/'.$fileName);
                    }),
            ])

            ->actions([
            ])
            ->bulkActions([
            ]);
    }
}
