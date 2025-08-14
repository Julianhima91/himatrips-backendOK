<?php

namespace App\Jobs;

use App\Enums\OfferCategoryEnum;
use App\Models\AdConfig;
use App\Models\Airport;
use App\Models\Destination;
use App\Settings\MonthlyWeekendAds;
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

class UpdateWeekendAdDestinationJob implements ShouldQueue
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
        $logger = Log::channel('weekend');

        $adConfig = $this->adConfig;
        $adConfigId = $this->adConfig->id;

        $logger->info('APPEND WEEKEND JOB');
        $logger->info("Destination ID: $this->destinationId");

        $allJobs = [];
        $batchIds = [];

        $destination = Destination::query()->find($this->destinationId);
        $origin = $adConfig->origin;
        $today = now();
        $endOfYear = now()->endOfYear();

        foreach ($adConfig->airports as $airport) {
            if (! $destination->is_active) {
                $logger->info('Skipping inactive destination ID: '.$destination->id);

                continue;
            }

            $destinationAirport = Airport::query()->whereHas('destinations', function ($query) use ($destination) {
                $query->where('destination_id', $destination->id);
            })->first();

            if (! $destinationAirport) {
                $logger->error("No airport found for destination: {$destination->id}");

                continue;
            }

            $today = now();
            $months = app(MonthlyWeekendAds::class)->monthly;
            $threeMonthsFromNow = now()->addMonths($months);

            $logger->info("MONTHS TO SEARCH: $months");
            $groupedWeekends = [];
            $requests = [];

            while ($today->lessThanOrEqualTo($threeMonthsFromNow)) {
                if ($today->isFriday()) {
                    $weekendStart = $today->toDateString();
                    $weekendEnd = $today->copy()->addDays(2)->toDateString(); // Sunday

                    if ($today->copy()->addDays(2)->lessThanOrEqualTo($threeMonthsFromNow)) {
                        $groupedWeekends[] = [
                            'date' => $weekendStart, // Friday
                            'return_date' => $weekendEnd, // Sunday
                        ];

                        $logger->info("Weekend dates: $weekendStart - $weekendEnd");
                    }
                }

                $today->addDay();
            }

            foreach ($groupedWeekends as $groupedWeekend) {
                $batchId = Str::orderedUuid();
                $batchIds[] = $batchId;

                $requests[] = [
                    'origin_airport' => $airport,
                    'destination_airport' => $destinationAirport,
                    'date' => $groupedWeekend['date'],
                    'nights' => 2,
                    'return_date' => $groupedWeekend['return_date'],
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
                    'category' => OfferCategoryEnum::WEEKEND->value,
                ];
            }

            //                $logger->info($groupedWeekends);

            foreach ($requests as $index => $request) {
                $logger->info("Generated Request nr. $index:\n".json_encode([
                    'origin_airport' => $airport['codeIataAirport'] ?? $airport->codeIataAirport,
                    'destination_airport' => $destinationAirport['codeIataAirport'] ?? $destinationAirport->codeIataAirport,
                    'date' => $request['date'],
                    'nights' => 2,
                    'return_date' => $request['return_date'],
                    'origin_id' => $adConfig->origin_id,
                    'destination_id' => $destination->id,
                    'batch_id' => $request['batch_id'],
                    'category' => OfferCategoryEnum::WEEKEND->value,
                ], JSON_PRETTY_PRINT));
                $logger->info('====================================================');

                $allJobs[] = new WeekendFlightSearch($request, $airport, $destinationAirport, 2, 0, 0, $batchId, $adConfigId);
                $allJobs[] = new WeekendHotelJob(
                    $request['nights'],
                    $request['destination_id'],
                    [['adults' => 2, 'children' => 0, 'infants' => 0]],
                    $batchId,
                    $request['date'],
                    $adConfigId,
                    $adConfig,
                );
                $allJobs[] = new FilterWeekendAds(
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
                WeekendAppendDestinationToCSVJob::dispatch($adConfig, $batchIds, $destinationId)->onQueue('weekend');
            })
            ->catch(function (Batch $batch, Throwable $e) {
                $logger = Log::channel('weekend');
                $logger->error('Weekend batch failed: '.$e->getMessage());
            })
            ->finally(function (Batch $batch) {
                $logger = Log::channel('weekend');
                $logger->info('Weekend batch finished');
            })
            ->onQueue('weekend')
            ->dispatch();
    }
}
