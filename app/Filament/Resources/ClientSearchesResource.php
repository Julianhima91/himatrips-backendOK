<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PackageSearchesResource\Pages\ListPackageSearches;
use App\Models\ClientSearches;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ClientSearchesResource extends Resource
{
    protected static ?string $model = ClientSearches::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema
            ->components([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(ClientSearches::query()->orderByDesc('package_created_at'))
            ->headerActions([
                Action::make('Fetch Latest Searches')
                    ->label('Fetch Latest')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function () {
                        \Artisan::call('client-searches:update');

                        Notification::make()
                            ->title('Latest client searches fetched successfully.')
                            ->success()
                            ->send();
                    })
                    ->requiresConfirmation(),
            ])
            ->columns([
                TextColumn::make('package_id')->label('ID')->sortable(),
                TextColumn::make('origin_name')->label('Origin')->sortable()->searchable(),
                TextColumn::make('destination_name')->label('Destination')->sortable()->searchable(),
                TextColumn::make('passengers')
                    ->label('Passengers')
                    ->getStateUsing(fn ($record) => "Ad: {$record->adults}, CHD: {$record->children}, INF: {$record->infants}"),
                TextColumn::make('created_at')->label('Date')->sortable(),
                TextColumn::make('url')
                    ->label('Search Link')
                    ->formatStateUsing(fn ($state, $record) => Action::make('searchLink')
                        ->label('LINK')
                        ->url($record->url)
                        ->color('success')
                        ->openUrlInNewTab()
                    ),
            ])
            ->filters([
                SelectFilter::make('origin')
                    ->label('Origin')
                    ->options(fn () => ClientSearches::pluck('origin_name', 'origin_id')->unique()),
                SelectFilter::make('destination')
                    ->label('Destination')
                    ->options(fn () => ClientSearches::pluck('destination_name', 'destination_id')->unique()),
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
}
