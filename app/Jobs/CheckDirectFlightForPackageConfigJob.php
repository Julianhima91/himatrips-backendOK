<?php

namespace App\Jobs;

use App\Http\Integrations\GoFlightIntegration\Requests\OneWayDirectFlightCalendarRequest;
use App\Models\Airport;
use App\Models\DirectFlightAvailability;
use App\Models\PackageConfig;
use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckDirectFlightForPackageConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $packageConfigId;

    /**
     * Create a new job instance.
     */
    public function __construct($packageConfigId = null)
    {
        $this->packageConfigId = $packageConfigId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        ray()->newScreen();

        if (! $this->packageConfigId) {
            $packageConfig = PackageConfig::first();
            if (! $packageConfig) {
                Log::info('No package configurations found.');

                return;
            }
            $this->packageConfigId = $packageConfig->id;
        } else {
            $packageConfig = PackageConfig::find($this->packageConfigId);
            if (! $packageConfig) {
                Log::info('No package configuration found for ID: '.$this->packageConfigId);

                return;
            }
        }

        $this->packageConfigId = $packageConfig->id;
        $origin = $packageConfig->destination_origin->origin;
        $destination = $packageConfig->destination_origin->destination;
        $originAirport = Airport::query()->where('origin_id', $origin->id)->first();
        $destinationAirport = Airport::query()->whereHas('destinations', function ($query) use ($destination) {
            $query->where('destination_id', $destination->id);
        })->first();

        $startDate = new DateTime('first day of this month'); // Starting from the current month
        $endDate = (new DateTime('first day of next year'))->modify('first day of this month');

        while ($startDate < $endDate) {
            $yearMonth = $startDate->format('Y-m');

            // Check if data for this package and month already exists
            $existingData = DirectFlightAvailability::where('destination_origin_id', $packageConfig->destination_origin_id)
                ->where('date', 'LIKE', "{$yearMonth}-%")
                ->exists();

            if (! $existingData) {
                ray($packageConfig->id, $yearMonth);

                $this->checkFlights($originAirport, $destinationAirport, $yearMonth, $packageConfig->destination_origin_id, false);
                $this->checkFlights($destinationAirport, $originAirport, $yearMonth, $packageConfig->destination_origin_id, true);
            } else {
                Log::info("Flights for {$yearMonth} already exist for package config ID: {$packageConfig->id}");
            }

            $startDate->modify('first day of next month');
        }

        $this->dispatchNextJob();
    }

    private function dispatchNextJob()
    {
        //        PackageConfig::where('id', '>', $this->packageConfigId)->chunk(1, function ($packageConfigs) {
        //            foreach ($packageConfigs as $packageConfig) {
        //                Log::info('PACKAGE ID: ' . $packageConfig->id);
        //                CheckDirectFlightForPackageConfigJob::dispatch($packageConfig->id)->delay(now()->addSeconds(1));
        //                break;
        //            }
        //        });

        $nextPackageConfig = PackageConfig::where('id', '>', $this->packageConfigId)
            ->orderBy('id')
            ->first();

        if ($nextPackageConfig) {
            Log::info('Next PACKAGE ID: '.$nextPackageConfig->id);
            CheckDirectFlightForPackageConfigJob::dispatch($nextPackageConfig->id)->delay(now()->addSeconds(1));
        } else {
            Log::info('No more package configurations found after ID: '.$this->packageConfigId);
        }
    }

    private function checkFlights($originAirport, $destinationAirport, $yearMonth, $destinationOriginId, $isReturnFlight)
    {
        $flightRequest = new OneWayDirectFlightCalendarRequest;

        $flightRequest->query()->merge([
            'fromEntityId' => $originAirport?->rapidapi_id ?? null,
            'toEntityId' => $destinationAirport?->rapidapi_id ?? null,
            'yearMonth' => $yearMonth,
        ]);

        try {
            $response = $flightRequest->send();

            $traces = $response->json()['data']['Traces'];

            foreach ($traces as $trace) {
                $arr = explode('*', $trace);

                if ($arr[1] === 'D') {
                    DirectFlightAvailability::updateOrCreate([
                        'date' => DateTime::createFromFormat('Ymd', $arr[4])->format('Y-m-d'),
                        'destination_origin_id' => $destinationOriginId,
                        'is_return_flight' => $isReturnFlight,
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('!!!ERROR!!!');
            Log::error($e->getMessage());
        }
    }
}
