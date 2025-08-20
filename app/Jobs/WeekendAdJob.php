<?php

namespace App\Jobs;

use App\Enums\OfferCategoryEnum;
use App\Models\AdConfig;
use App\Models\Airport;
use App\Settings\MonthlyWeekendAds;
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

class WeekendAdJob implements ShouldQueue
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
        if (! $adConfig) {
            Log::warning('WeekendAdJob: AdConfig not found', ['ad_config_id' => $this->adConfigId]);

            return;
        }

        $logger = Log::channel('weekend');
        $logger->info('STARTING WEEKEND JOB');

        $adConfig->weekend_last_run = Carbon::now();
        $adConfig->save();
        $allJobs = [];
        $batchIds = [];
        $adConfigId = $this->adConfigId;

        $origin = $adConfig->origin;

        foreach ($adConfig->airports as $airport) {
            foreach ($adConfig->destinations as $destination) {
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

                //                $batchIds = array_map('strval', array_column($requests, 'batch_id'));

                //                $logger->info('===================================');
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

                    $allJobs[] = new WeekendFlightSearch($request, $airport, $destinationAirport, 2, 0, 0, $batchId, $this->adConfigId);
                    $allJobs[] = new WeekendHotelJob(
                        $request['nights'],
                        $request['destination_id'],
                        [['adults' => 2, 'children' => 0, 'infants' => 0]],
                        $batchId,
                        $request['date'],
                        $this->adConfigId,
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
        }

        Bus::batch($allJobs)
            ->then(function (Batch $batch) {
                //                $logger = Log::channel('weekend');
                //                $logger->error('INSIDE THE CSV SECTION');
                //
                //                WeekendCSVJob::dispatch($adConfig, $batchIds)->onQueue('weekend');
            })
            ->catch(function (Batch $batch, Throwable $e) use ($adConfigId) {
                $logger = Log::channel('weekend');
                $logger->error('Weekend batch failed: '.$e->getMessage());

                $adConfig1 = AdConfig::find($adConfigId);
                $adConfig1->update(['weekend_status' => 'failed']);
            })
            ->finally(function (Batch $batch) use ($adConfigId, $adConfig, $batchIds) {
                $logger = Log::channel('weekend');
                $logger->info('Weekend batch finished');

                $adConfig1 = AdConfig::find($adConfigId);
                $adConfig1->update(['weekend_status' => 'completed']);

                WeekendCSVJob::dispatch($adConfig, $batchIds)->onQueue('weekend');
            })
            ->onQueue('weekend')
            ->dispatch();
    }
}
