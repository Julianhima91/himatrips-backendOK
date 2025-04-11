<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HolidayResource\Pages;
use App\Models\Country;
use App\Models\Holiday;
use Filament\Forms;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class HolidayResource extends Resource
{
    protected static ?string $model = Holiday::class;

    protected static ?string $navigationGroup = 'Advertising';

    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('country_id')
                    ->label('Country')
                    ->options(
                        Country::query()
                            ->pluck('name', 'id')
                    )
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
                Tables\Columns\TextColumn::make('country.name')
                    ->label('Country')
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
            ->headerActions([
                Tables\Actions\Action::make('Import')
                    ->modalHeading('Import Data via CSV')
                    ->form([
                        Select::make('country_id')
                            ->label('Country')
                            ->options(
                                Country::query()
                                    ->pluck('name', 'id')
                            )
                            ->required(),
                        FileUpload::make('file')
                            ->label('CSV File')
                            ->required()
                            ->acceptedFileTypes(['text/csv', 'application/csv'])
                            ->helperText('Upload your CSV file with holidays.'),
                    ])
                    ->action(function (array $data) {
                        $fileName = $data['file'];
                        $country = $data['country_id'];

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
                                    'country_id' => $country,
                                ]);
                            }
                        }

                        Storage::delete('public/'.$fileName);
                    }),
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
