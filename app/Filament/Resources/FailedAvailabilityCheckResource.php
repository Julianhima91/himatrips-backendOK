<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FailedAvailabilityCheckResource\Pages;
use App\Jobs\RetryFailedAvailabilityJob;
use App\Models\Airport;
use App\Models\DestinationOrigin;
use App\Models\FailedAvailabilityCheck;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FailedAvailabilityCheckResource extends Resource
{
    protected static ?string $model = FailedAvailabilityCheck::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Failed Availability Checks';

    protected static string|\UnitEnum|null $navigationGroup = 'Flight Management';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('year_month')
                    ->label('Year-Month')
                    ->disabled()
                    ->formatStateUsing(fn ($state) => $state ?? 'N/A'),
                Forms\Components\Toggle::make('is_return_flight')
                    ->label('Is Return Flight')
                    ->disabled(),
                Forms\Components\Textarea::make('error_message')
                    ->label('Error Message')
                    ->disabled()
                    ->rows(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('destination_origin.origin.name')
                    ->label('Origin')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('destination_origin.destination.name')
                    ->label('Destination')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('year_month')
                    ->label('Year-Month')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\IconColumn::make('is_return_flight')
                    ->label('Return Flight')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('error_message')
                    ->label('Error')
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->error_message)
                    ->wrap(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Failed At')
                    ->dateTime()
                    ->sortable()
                    ->since(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('is_return_flight')
                    ->label('Flight Type')
                    ->options([
                        0 => 'Outbound',
                        1 => 'Return',
                    ]),
                Tables\Filters\Filter::make('destination_origin_id')
                    ->form([
                        Forms\Components\Select::make('destination_origin_id')
                            ->label('Route')
                            ->options(function () {
                                return DestinationOrigin::with(['origin', 'destination'])
                                    ->get()
                                    ->mapWithKeys(function ($do) {
                                        $label = ($do->origin?->name ?? 'N/A') . ' → ' . ($do->destination?->name ?? 'N/A');
                                        return [$do->id => $label];
                                    });
                            })
                            ->searchable(),
                    ])
                    ->query(function ($query, array $data) {
                        return $query->when(
                            $data['destination_origin_id'],
                            fn ($query, $value) => $query->where('destination_origin_id', $value)
                        );
                    }),
            ])
            ->actions([
                Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (FailedAvailabilityCheck $record) {
                        try {
                            $originAirport = Airport::find($record->origin_airport_id);
                            $destinationAirport = Airport::find($record->destination_airport_id);

                            if (!$originAirport || !$destinationAirport) {
                                Notification::make()
                                    ->danger()
                                    ->title('Missing Airport Data')
                                    ->body('Origin or destination airport not found.')
                                    ->send();
                                return;
                            }

                            $job = new RetryFailedAvailabilityJob();
                            $success = $job->retryFlightCheck(
                                $originAirport,
                                $destinationAirport,
                                $record->year_month,
                                $record->destination_origin_id,
                                (bool) $record->is_return_flight,
                                $record->id
                            );

                            if ($success) {
                                Notification::make()
                                    ->success()
                                    ->title('Retry Successful')
                                    ->body('Failed check has been retried and removed.')
                                    ->send();
                            } else {
                                Notification::make()
                                    ->warning()
                                    ->title('Retry Failed')
                                    ->body('The retry attempt failed. Check logs for details.')
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->danger()
                                ->title('Error')
                                ->body('An error occurred: ' . $e->getMessage())
                                ->send();
                        }
                    }),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('retry_selected')
                        ->label('Retry Selected')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $logger = Log::channel('directdates');
                            $successCount = 0;
                            $failCount = 0;

                            foreach ($records as $record) {
                                try {
                                    $originAirport = Airport::find($record->origin_airport_id);
                                    $destinationAirport = Airport::find($record->destination_airport_id);

                                    if (!$originAirport || !$destinationAirport) {
                                        $logger->warning("Missing airport data for failed check ID: {$record->id}");
                                        $failCount++;
                                        continue;
                                    }

                                    $job = new RetryFailedAvailabilityJob();
                                    $success = $job->retryFlightCheck(
                                        $originAirport,
                                        $destinationAirport,
                                        $record->year_month,
                                        $record->destination_origin_id,
                                        (bool) $record->is_return_flight,
                                        $record->id
                                    );

                                    if ($success) {
                                        $successCount++;
                                    } else {
                                        $failCount++;
                                    }

                                    usleep(500000); // 0.5 seconds delay between retries
                                } catch (\Exception $e) {
                                    $logger->error("Error retrying failed check ID {$record->id}: {$e->getMessage()}");
                                    $failCount++;
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title('Bulk Retry Completed')
                                ->body("Successfully retried: {$successCount}, Failed: {$failCount}")
                                ->send();
                        }),
                    BulkAction::make('retry_all')
                        ->label('Retry All Failed Checks')
                        ->icon('heroicon-o-arrow-path')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function () {
                            RetryFailedAvailabilityJob::dispatch();

                            Notification::make()
                                ->success()
                                ->title('Retry Job Dispatched')
                                ->body('All failed checks are being retried in the background.')
                                ->send();
                        }),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No Failed Checks')
            ->emptyStateDescription('All availability checks are successful!')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFailedAvailabilityChecks::route('/'),
        ];
    }
}

