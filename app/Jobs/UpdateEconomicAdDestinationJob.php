<?php

namespace App\Jobs;

use App\Models\AdConfig;
use App\Models\Airport;
use App\Models\Destination;
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

class UpdateEconomicAdDestinationJob implements ShouldQueue
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
        $logger = Log::channel('economic');

        $adConfig = $this->adConfig;
        $destination = Destination::query()->findOrFail($this->destinationId);

        $batchIds = [];
        $allJobs = [];

        foreach ($adConfig->airports as $airport) {
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
                $allJobs[] = new CheckEconomicFlightJob($airport, $destinationAirport, $month, $this->adConfig->id, $batchId, false);
                $allJobs[] = new CheckEconomicFlightJob($airport, $destinationAirport, $month, $this->adConfig->id, $batchId, true);
                $allJobs[] = new ProcessEconomicResponsesJob($batchId, $this->adConfig->id, $destination->ad_min_nights, $adConfig, $destination);
                $allJobs[] = new EconomicFlightSearch($month, $airport, $destinationAirport, 2, 0, 0, $batchId, $this->adConfig->id);
                $allJobs[] = new EconomicHotelJob(
                    $destination->ad_min_nights,
                    $destination->id,
                    [['adults' => 2, 'children' => 0, 'infants' => 0]],
                    $batchId,
                    $month,
                    $this->adConfig->id,
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

        $destinationId = $this->destinationId;
        Bus::batch($allJobs)
            ->then(function (Batch $batch) use ($adConfig, $batchIds, $destinationId) {
                AppendDestinationToCSVJob::dispatch($adConfig, $batchIds, $destinationId);
            })
            ->catch(function (Batch $batch, Throwable $e) {
                Log::error('Economic batch failed: '.$e->getMessage());
            })
            ->finally(function (Batch $batch) {
                Log::info('Economic batch finished');
            })
            ->onQueue('economic')
            ->dispatch();

    }
}
