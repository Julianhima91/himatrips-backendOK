<?php

namespace App\Jobs;

use App\Http\Integrations\GoFlightIntegration\Requests\OneWayDirectFlightCalendarRequest;
use App\Models\Airport;
use App\Models\DirectFlightAvailability;
use DateTime;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RetryFailedAvailabilityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct() {}

    public function handle(): void
    {
        $logger = Log::channel('directdates');
        $logger->info('Starting retry of failed availability checks');

        $failedChecks = DB::table('failed_availability_checks')->get();

        if ($failedChecks->isEmpty()) {
            $logger->info('No failed availability checks to retry');

            return;
        }

        $logger->info("Found {$failedChecks->count()} failed checks to retry");

        foreach ($failedChecks as $failedCheck) {
            $logger->info("Retrying failed check ID: {$failedCheck->id} - {$failedCheck->year_month}");

            $originAirport = Airport::find($failedCheck->origin_airport_id);
            $destinationAirport = Airport::find($failedCheck->destination_airport_id);

            if (! $originAirport || ! $destinationAirport) {
                $logger->warning("Missing airport data for failed check ID: {$failedCheck->id}");

                continue;
            }

            $this->retryFlightCheck(
                $originAirport,
                $destinationAirport,
                $failedCheck->year_month,
                $failedCheck->destination_origin_id,
                (bool) $failedCheck->is_return_flight,
                $failedCheck->id,
            );

            usleep(500000); // 0.5 seconds
        }

        $logger->info('Retry completed.');
    }

    protected function retryFlightCheck($originAirport, $destinationAirport, $yearMonth, $destinationOriginId, $isReturnFlight, $failedCheckId): bool
    {
        $logger = Log::channel('directdates');

        $flightRequest = new OneWayDirectFlightCalendarRequest;
        $flightRequest->query()->merge([
            'fromEntityId' => $originAirport->rapidapi_id,
            'toEntityId' => $destinationAirport->rapidapi_id,
            'yearMonth' => $yearMonth,
        ]);

        try {
            $response = $flightRequest->send();
            $responseData = $response->json();

            if (! isset($responseData['data']['PriceGrids']['Grid'][0])) {
                throw new Exception('Invalid API response structure');
            }

            $grids = $responseData['data']['PriceGrids']['Grid'][0];

            $availabilityData = [];
            [$year, $month] = explode('-', $yearMonth);

            foreach ($grids as $index => $grid) {
                if (! empty($grid['DirectOutboundAvailable'])) {
                    $dayOfMonth = $index + 1;

                    if (! checkdate((int) $month, $dayOfMonth, (int) $year)) {
                        $logger->warning("Invalid date: $year-$month-$dayOfMonth");

                        continue;
                    }

                    $date = new DateTime;
                    $date->setDate((int) $year, (int) $month, $dayOfMonth);

                    $availabilityData[] = [
                        'date' => $date->format('Y-m-d'),
                        'destination_origin_id' => $destinationOriginId,
                        'is_return_flight' => $isReturnFlight,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    $logger->info("Retry - Date: {$date->format('Y-m-d')} (Is Return: ".($isReturnFlight ? 'Yes' : 'No').')');
                }
            }

            if (! empty($availabilityData)) {
                $this->batchUpsertAvailability($availabilityData);
            }

            DB::table('failed_availability_checks')->where('id', $failedCheckId)->delete();

            return true;

        } catch (Exception $e) {
            $logger->error("Retry failed for $yearMonth. Error: ".$e->getMessage());

            return false;
        }
    }

    private function batchUpsertAvailability(array $data): void
    {
        DirectFlightAvailability::upsert(
            $data,
            ['date', 'destination_origin_id', 'is_return_flight'],
            ['updated_at']
        );
    }
}
