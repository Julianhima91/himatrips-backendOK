<?php

namespace App\Filament\Resources\CountryResource\RelationManagers;

use App\Models\Holiday;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class HolidaysRelationManager extends RelationManager
{
    protected static string $relationship = 'holidays';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Holiday Name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('day')
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
                TextColumn::make('name'),
                TextColumn::make('day'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make(),
                Action::make('Import')
                    ->modalHeading('Import Data via CSV')
                    ->schema([
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

            ->recordActions([
            ])
            ->toolbarActions([
            ]);
    }
}
