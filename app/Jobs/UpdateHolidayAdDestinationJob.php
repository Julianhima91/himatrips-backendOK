<?php

namespace App\Jobs;

use App\Enums\OfferCategoryEnum;
use App\Models\AdConfig;
use App\Models\Airport;
use App\Models\Destination;
use App\Models\DestinationOrigin;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class UpdateHolidayAdDestinationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $destinationId;

    private AdConfig $adConfig;

    public function __construct($destinationId, $adConfig)
    {
        $this->destinationId = $destinationId;
        $this->adConfig = $adConfig;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $logger = Log::channel('holiday');

        $adConfig = $this->adConfig;
        $adConfigId = $this->adConfig->id;

        $logger->info('APPEND HOLIDAY JOB');
        $logger->info("Destination ID: $this->destinationId");

        $allJobs = [];
        $batchIds = [];

        $destination = Destination::query()->find($this->destinationId);
        $origin = $adConfig->origin;
        $today = now();
        $endOfYear = now()->endOfYear();

        foreach ($adConfig->airports as $airport) {
            $destinationAirport = Airport::query()->whereHas('destinations', function ($query) use ($destination) {
                $query->where('destination_id', $destination->id);
            })->first();

            if (! $destination->is_active) {
                Log::info('Skipping inactive destination ID: '.$destination->id);

                continue;
            }

            $destinationOrigin = DestinationOrigin::where([
                ['destination_id', $destination->id],
                ['origin_id', $adConfig->origin_id],
            ])->first();

            if (! $destinationOrigin || ! $destinationOrigin->min_nights || ! $destinationOrigin->max_nights) {
                $logger->error("Missing min or max nights for destination_origin: {$destinationOrigin->id}");

                continue;
            }

            $holidays = $origin
                ->getRelationValue('country')
                ->holidays()
                ->get()
                ->filter(function ($holiday) use ($today, $endOfYear, $logger, $destination) {
                    [$day, $month] = explode('-', $holiday->day);

                    $logger->warning("HOLIDAY: $holiday->name - $holiday->day | DESTINATION: $destination->name");
                    $holidayDate = now()->startOfYear()->setMonth($month)->setDay($day);

                    return $holidayDate->between($today, $endOfYear);
                })
                ->pluck('day')
                ->map(function ($holiday) {
                    [$holidayDay, $holidayMonth] = explode('-', $holiday);

                    return now()->startOfYear()
                        ->setMonth($holidayMonth)
                        ->setDay($holidayDay)
                        ->timezone('Europe/Amsterdam');
                })
                ->toArray();

            $destinationHolidays = $holidays;

            $requests = [];

            foreach ($holidays as $holiday) {
                $minNights = $destinationOrigin->min_nights;
                $maxNights = $destinationOrigin->max_nights;

                for ($nights = $minNights; $nights <= $maxNights; $nights++) {
                    for ($startOffset = 1; $startOffset <= max(1, $nights - 1); $startOffset++) {
                        $startDate = $holiday->copy()->subDays($startOffset);
                        $endDate = $startDate->copy()->addDays($nights);

                        if ($startDate->between($today, $endOfYear) && $endDate->between($today, $endOfYear)) {
                            $batchId = Str::orderedUuid();
                            $batchIds[] = $batchId;

                            $requests[] = [
                                'origin_airport' => $airport,
                                'destination_airport' => $destinationAirport,
                                'date' => $startDate->toDateString(),
                                'nights' => $nights,
                                'return_date' => $endDate->toDateString(),
                                'origin_id' => $adConfig->origin_id,
                                'destination_id' => $destination->id,
                                'rooms' => [
                                    [
                                        'adults' => 2,
                                        'children' => 0,
                                        'infants' => 0,
                                    ],
                                ],
                                'batch_id' => $batchId,
                                'category' => OfferCategoryEnum::HOLIDAY->value,
                                'holidays' => $destinationHolidays,
                            ];
                        }
                    }
                }
            }

            foreach ($requests as $index => $request) {
                $logger->info("Generated Request nr. $index:\n".json_encode([
                    'origin_airport' => $airport['codeIataAirport'] ?? $airport->codeIataAirport,
                    'destination_airport' => $destinationAirport['codeIataAirport'] ?? $destinationAirport->codeIataAirport,
                    'date' => $request['date'],
                    'nights' => $request['nights'],
                    'return_date' => $request['return_date'],
                    'origin_id' => $adConfig->origin_id,
                    'destination_id' => $destination->id,
                    'batch_id' => $request['batch_id'],
                    'category' => OfferCategoryEnum::HOLIDAY->value,
                    'holidays' => $destinationHolidays,
                ], JSON_PRETTY_PRINT));

                $allJobs[] = new HolidayFlightSearch($request, $airport, $destinationAirport, 2, 0, 0, $batchId, $this->adConfig);
                $allJobs[] = new HolidayHotelJob(
                    $request['nights'],
                    $request['destination_id'],
                    [['adults' => 2, 'children' => 0, 'infants' => 0]],
                    $batchId,
                    $request['date'],
                    $this->adConfig,
                    $adConfig,
                );
                $allJobs[] = new FilterHolidayAds(
                    $batchId,
                    $adConfig,
                    $request['date'],
                    $request['return_date'],
                    $adConfig->origin_id,
                    $destination->id,
                    $airport,
                    $destinationAirport,
                    $batchIds
                );
            }

        }

        $destinationId = $this->destinationId;
        Bus::batch($allJobs)
            ->then(function (Batch $batch) use ($adConfig, $batchIds, $destinationId) {
                HolidayAppendDestinationToCSVJob::dispatch($adConfig, $batchIds, $destinationId)->onQueue('holiday');
            })
            ->catch(function (Batch $batch, Throwable $e) {
                $logger = Log::channel('holiday');
                $logger->error('Holiday batch failed: '.$e->getMessage());
            })
            ->finally(function (Batch $batch) {
                $logger = Log::channel('holiday');
                $logger->info('Holiday batch finished');
            })
            ->onQueue('holiday')
            ->dispatch();
    }
}
