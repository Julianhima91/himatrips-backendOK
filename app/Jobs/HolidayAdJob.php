<?php

namespace App\Jobs;

use App\Enums\OfferCategoryEnum;
use App\Models\AdConfig;
use App\Models\Airport;
use App\Models\DestinationOrigin;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class HolidayAdJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $adConfigId;

    public function __construct($adConfigId)
    {
        $this->adConfigId = $adConfigId;
    }

    public function handle(): void
    {
        $adConfig = AdConfig::find($this->adConfigId);
        $logger = Log::channel('holiday');
        $adConfigId = $this->adConfigId;

        $logger->info('STARTING HOLIDAY JOB');

        $adConfig->holiday_last_run = Carbon::now();
        $adConfig->save();

        $allJobs = [];
        $batchIds = [];
        $origin = $adConfig->origin;
        $today = now();
        $endOfYear = now()->endOfYear();

        foreach ($adConfig->airports as $airport) {
            foreach ($adConfig->destinations as $destination) {
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

                //                $batchIds = array_map('strval', array_column($requests, 'batch_id'));
                //        $logger->warning(count($requests) . ' requests created.');

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

                    $allJobs[] = new HolidayFlightSearch($request, $airport, $destinationAirport, 2, 0, 0, $batchId, $this->adConfigId);
                    $allJobs[] = new HolidayHotelJob(
                        $request['nights'],
                        $request['destination_id'],
                        [['adults' => 2, 'children' => 0, 'infants' => 0]],
                        $batchId,
                        $request['date'],
                        $this->adConfigId,
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
        }

        Bus::batch($allJobs)
            ->then(function (Batch $batch) {
                //                $logger = Log::channel('holiday');
                //                $logger->error('INSIDE THE CSV SECTION');
                //
                //                HolidayCSVJob::dispatch($adConfig, $batchIds)->onQueue('holiday');
            })
            ->catch(function (Batch $batch, Throwable $e) use ($adConfigId) {
                $logger = Log::channel('holiday');
                $logger->error('Holiday batch failed: '.$e->getMessage());

                $adConfig1 = AdConfig::find($adConfigId);
                $adConfig1->update(['holiday_status' => 'failed']);
            })
            ->finally(function (Batch $batch) use ($adConfigId, $adConfig, $batchIds) {
                $logger = Log::channel('holiday');
                $logger->info('Holiday batch finished');

                $adConfig1 = AdConfig::find($adConfigId);
                $adConfig1->update(['holiday_status' => 'completed']);

                //todo: we can remove it from then
                HolidayCSVJob::dispatch($adConfig, $batchIds)->onQueue('holiday');
            })
            ->onQueue('holiday')
            ->dispatch();
    }
}
