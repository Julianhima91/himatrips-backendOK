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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ProcessPackageConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected PackageConfig $packageConfig;

    public function __construct(PackageConfig $packageConfig)
    {
        $this->packageConfig = $packageConfig;
    }

    public function handle(): void
    {
        $logger = Log::channel('directdates');

        $origin = $this->packageConfig->destination_origin->origin;
        $destination = $this->packageConfig->destination_origin->destination;

        $originAirport = Airport::where('origin_id', $origin->id)->first();
        $destinationAirport = Airport::whereHas('destinations', function ($q) use ($destination) {
            $q->where('destination_id', $destination->id);
        })->first();

        $startDate = new DateTime('first day of this month');
        $endDate = (new DateTime('first day of next year'))->modify('first day of this month');

        while ($startDate < $endDate) {
            $yearMonth = $startDate->format('Y-m');
            $lastProcessedMonth = $this->packageConfig->last_processed_month;

            if ($lastProcessedMonth) {
                $yearMonthDate = Carbon::createFromFormat('Y-m', $yearMonth);
                $lastProcessedMonthDate = Carbon::createFromFormat('Y-m', $lastProcessedMonth);

                if ($yearMonthDate->lessThanOrEqualTo($lastProcessedMonthDate)) {
                    $logger->info("Skipping month $yearMonth for PackageConfig ID {$this->packageConfig->id} (Already processed)");
                    $startDate->modify('first day of next month');

                    continue;
                }
            }

            $logger->info("Checking flights for PackageConfig ID {$this->packageConfig->id} - Month $yearMonth");

            $this->checkFlights($originAirport, $destinationAirport, $yearMonth, $this->packageConfig->destination_origin_id, false);
            $this->checkFlights($destinationAirport, $originAirport, $yearMonth, $this->packageConfig->destination_origin_id, true);

            $this->packageConfig->last_processed_month = $yearMonth;
            $this->packageConfig->save();

            $startDate->modify('first day of next month');
        }

        $logger->info('|===========================================================|');
        $logger->info("|FINISHED $origin->name - $destination->name SUCCESSFULLY|");
        $logger->info('|===========================================================|');
    }

    protected function checkFlights($originAirport, $destinationAirport, $yearMonth, $destinationOriginId, $isReturnFlight)
    {
        $logger = Log::channel('directdates');

        if (! $originAirport || ! $destinationAirport) {
            $logger->warning("Missing airport data. Skipping check. From: {$originAirport?->id}, To: {$destinationAirport?->id}");

            return;
        }

        $flightRequest = new OneWayDirectFlightCalendarRequest;

        $flightRequest->query()->merge([
            'fromEntityId' => $originAirport->rapidapi_id,
            'toEntityId' => $destinationAirport->rapidapi_id,
            'yearMonth' => $yearMonth,
        ]);

        try {
            $response = $flightRequest->send();
            $grids = $response->json()['data']['PriceGrids']['Grid'][0] ?? [];

            foreach ($grids as $index => $grid) {
                if (! empty($grid['DirectOutboundAvailable'])) {
                    [$year, $month] = explode('-', $yearMonth);
                    $date = new DateTime;
                    $date->setDate((int) $year, (int) $month, $index + 1);

                    DirectFlightAvailability::updateOrCreate([
                        'date' => $date->format('Y-m-d'),
                        'destination_origin_id' => $destinationOriginId,
                        'is_return_flight' => $isReturnFlight,
                    ]);

                    $logger->info("Date: {$date->format('Y-m-d')} (Is Return: ".($isReturnFlight ? 'Yes' : 'No').')');
                }
            }
        } catch (\Exception $e) {
            $logger->error("Failed to check flights for $yearMonth. Error: ".$e->getMessage());
        }
    }
}
