<?php

namespace App\Jobs;

use App\Models\AdConfig;
use App\Models\Airport;
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

class EconomicAdJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $adConfigId;

    public function __construct($adConfigId)
    {
        $this->adConfigId = $adConfigId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $adConfig = AdConfig::find($this->adConfigId);

        $adConfigId = $this->adConfigId;

        $adConfig->holiday_last_run = Carbon::now();
        $adConfig->save();

        $allJobs = [];
        $batchIds = [];
        $logger = Log::channel('economic');

        foreach ($adConfig->airports as $airport) {
            foreach ($adConfig->destinations as $destination) {
                $destinationAirport = Airport::query()->whereHas('destinations', function ($query) use ($destination) {
                    $query->where('destination_id', $destination->id);
                })->first();

                if (! $destination->is_active) {
                    $logger->info('Skipping inactive destination ID: '.$destination->id);

                    continue;
                }

                $months = collect($destination->active_months)
                    ->map(fn ($month) => now()->format('Y').'-'.$month)
                    ->filter(fn ($month) => Carbon::parse($month)->greaterThanOrEqualTo(now()->startOfMonth()))
                    ->mapWithKeys(fn ($month) => [(string) Str::orderedUuid() => $month])
                    ->toArray();

                $currentBatchIds = array_keys($months);
                $batchIds = array_merge($batchIds, $currentBatchIds);

                $logger->info($adConfig->origin->name.'-'.$destination->name);
                $logger->info($months);

                foreach ($months as $batchId => $month) {
                    $allJobs[] = new CheckEconomicFlightJob($airport, $destinationAirport, $month, $this->adConfigId, $batchId, false);
                    $allJobs[] = new CheckEconomicFlightJob($airport, $destinationAirport, $month, $this->adConfigId, $batchId, true);
                    $allJobs[] = new ProcessEconomicResponsesJob($batchId, $this->adConfigId, $destination->ad_min_nights, $adConfig, $destination);
                    $allJobs[] = new EconomicFlightSearch($month, $airport, $destinationAirport, 2, 0, 0, $batchId, $this->adConfigId);
                    $allJobs[] = new EconomicHotelJob(
                        $destination->ad_min_nights,
                        $destination->id,
                        [['adults' => 2, 'children' => 0, 'infants' => 0]],
                        $batchId,
                        $month,
                        $this->adConfigId,
                        $adConfig,
                    );
                    $allJobs[] = new FilterEconomicAds(
                        $batchId,
                        $adConfig,
                        $month,
                        $adConfig->origin_id,
                        $destination->id,
                        $airport,
                        $destinationAirport,
                        $currentBatchIds
                    );
                }
            }
        }

        Bus::batch($allJobs)
            ->then(function (Batch $batch) {
                //                $logger = Log::channel('economic');
                //                $logger->error('INSIDE THE CSV SECTION');
                //
                //                EconomicCSVJob::dispatch($adConfig, $batchIds)->onQueue('economic');
            })
            ->catch(function (Batch $batch, Throwable $e) use ($adConfigId) {
                $logger = Log::channel('economic');
                $logger->error('Economic batch failed: '.$e->getMessage());
                Log::info($adConfigId);
                $adConfig1 = AdConfig::find($adConfigId);
                $adConfig1->update(['economic_status' => 'failed']);
            })
            ->finally(function (Batch $batch) use ($adConfigId, $adConfig, $batchIds) {
                $logger = Log::channel('economic');
                $logger->info('Economic batch finished');

                $adConfig1 = AdConfig::find($adConfigId);
                $adConfig1->update(['economic_status' => 'completed']);

                EconomicCSVJob::dispatch($adConfig, $batchIds)->onQueue('economic');
            })
            ->onQueue('economic')
            ->dispatch();
    }
}
