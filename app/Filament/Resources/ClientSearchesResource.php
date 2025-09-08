<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PackageSearchesResource\Pages\ListPackageSearches;
use App\Models\ClientSearches;
use DB;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ClientSearchesResource extends Resource
{
    protected static ?string $model = ClientSearches::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
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
                        $query->select(DB::raw('MIN(id)'))
                            ->from('packages')
                            ->groupBy('batch_id');
                    })
                    ->orderBy('created_at', 'desc')
            )->poll()
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('packageConfig.destination_origin.origin.name')
                    ->label('Origin')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('packageConfig.destination_origin.destination.name')
                    ->label('Destination')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('passengers')
                    ->label('Passengers')
                    ->getStateUsing(fn ($record) => "Ad: {$record->hotelData->adults}, CHD: {$record->hotelData->children}, INF: {$record->hotelData->infants}"),
                TextColumn::make('created_at')
                    ->label('Date')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('batch_id')
                    ->label('Search Link')
                    ->formatStateUsing(fn ($state, $record) => Action::make('searchLink')
                        ->label('LINK')
                        ->url(fn () => config('app.front_url').'/search-'.strtolower(
                            str_replace(' ', '-', "{$record->packageConfig->destination_origin->origin->name}").
                            '-to-'.
                            str_replace(' ', '-', "{$record->packageConfig->destination_origin->destination->name}")
                        ).'?&query='.base64_encode(http_build_query([
                            'batch_id' => $record->batch_id,
                            'nights' => $record->hotelData->number_of_nights,
                            'checkin_date' => $record->hotelData->check_in_date,
                            'origin_id' => $record->packageConfig->destination_origin->origin->id,
                            'destination_id' => $record->packageConfig->destination_origin->destination->id,
                            'page' => 1,
                            'rooms' => $record->hotelData->room_object,
                            'directFlightsOnly' => $record->inboundFlight->stop_count === 0 ? 'true' : 'false',
                            'sort_by' => 'total_price',
                            'sort_order' => 'ASC',
                            'adults' => $record->hotelData->adults,
                            'infants' => $record->hotelData->infants,
                            'children' => $record->hotelData->children,
                            'refresh' => 0,
                        ])))
                        ->color('success')
                        ->openUrlInNewTab()
                    ),
            ])
            ->filters([
                SelectFilter::make('origin')
                    ->label('Origin')
                    ->relationship('packageConfig.destination_origin.origin', 'name'),
                SelectFilter::make('destination')
                    ->label('Destination')
                    ->relationship('packageConfig.destination_origin.destination', 'name'),
                Filter::make('created_at')
                    ->label('Created At')
                    ->schema([
                        DatePicker::make('from_date')->label('From Date'),
                        DatePicker::make('to_date')->label('To Date'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from_date'], fn ($q) => $q->whereDate('created_at', '>=', $data['from_date']))
                            ->when($data['to_date'], fn ($q) => $q->whereDate('created_at', '<=', $data['to_date']));
                    }),
            ])
            ->recordActions([
                Action::make('Flights Json')
                    ->action(fn (ClientSearches $record) => true)
                    ->fillForm(fn (ClientSearches $record): array => [
                        'all_flights' => $record->inboundFlight->all_flights,
                    ])
                    ->schema([
                        TextArea::make('all_flights')
                            ->disabled()
                            ->autosize(true)
                            ->label('Flight Json'),
                    ])
                    ->modalHeading('View All flights json')
                    ->modalDescription('Json response for all flights')
                    ->modalSubmitActionLabel('Close')
                    ->slideOver(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
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
            'index' => ListPackageSearches::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
