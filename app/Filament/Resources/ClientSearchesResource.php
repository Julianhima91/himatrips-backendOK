<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PackageSearchesResource\Pages;
use App\Models\ClientSearches;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ClientSearchesResource extends Resource
{
    protected static ?string $model = ClientSearches::class;

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(
                ClientSearches::query()
                    ->select('packages.*')
                    ->whereIn('id', function ($query) {
                        $query->select(\DB::raw('MIN(id)'))
                            ->from('packages')
                            ->groupBy('batch_id');
                    })
                    ->orderBy('created_at', 'desc')
            )->poll()
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('packageConfig.destination_origin.origin.name')
                    ->label('Origin')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('packageConfig.destination_origin.destination.name')
                    ->label('Destination')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('passengers')
                    ->label('Passengers')
                    ->getStateUsing(fn ($record) => "Ad: {$record->hotelData->adults}, CHD: {$record->hotelData->children}, INF: {$record->hotelData->infants}"),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('batch_id')
                    ->label('Search Link')
                    ->formatStateUsing(fn ($state, $record) => Action::make('searchLink')
                        ->label('LINK')
                        ->url(fn () => env('FRONT_URL').'/search-'.strtolower(
                            str_replace(' ', '-', "{$record->packageConfig->destination_origin->origin->name}").
                            '-to-'.
                            str_replace(' ', '-', "{$record->packageConfig->destination_origin->destination->name}")
                            ."?batch_id={$record->batch_id}"
                            ."&nights={$record->hotelData->number_of_nights}"
                            ."&checkin_date={$record->hotelData->check_in_date}"
                            ."&origin_id={$record->packageConfig->destination_origin->origin->id}"
                            ."&destination_id={$record->packageConfig->destination_origin->destination->id}"
                            .'&page=1'
                            ."&rooms={$record->hotelData->room_count}"
                            .'&directFlightsOnly='.($record->inboundFlight->stop_count === 0 ? 'true' : 'false')
                            .'&sort_by=total_price'
                            .'&sort_order=ASC'
                            ."&adults={$record->hotelData->adults}"
                            ."&infants={$record->hotelData->infants}"
                            ."&children={$record->hotelData->children}"
                            .'&refresh=0')
                        )
                        ->color('success')
                        ->openUrlInNewTab()
                    ),

            ])
            ->filters([
                Tables\Filters\SelectFilter::make('origin')
                    ->label('Origin')
                    ->relationship('packageConfig.destination_origin.origin', 'name'),
                Tables\Filters\SelectFilter::make('destination')
                    ->label('Destination')
                    ->relationship('packageConfig.destination_origin.destination', 'name'),
                Tables\Filters\Filter::make('created_at')
                    ->label('Created At')
                    ->form([
                        Forms\Components\DatePicker::make('from_date')->label('From Date'),
                        Forms\Components\DatePicker::make('to_date')->label('To Date'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from_date'], fn ($q) => $q->whereDate('created_at', '>=', $data['from_date']))
                            ->when($data['to_date'], fn ($q) => $q->whereDate('created_at', '<=', $data['to_date']));
                    }),
            ])
            ->actions([
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateActions([
            ])
            ->paginated([10, 25, 50, 100]);
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
            'index' => Pages\ListPackageSearches::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
