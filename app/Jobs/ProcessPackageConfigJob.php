<?php

namespace App\Jobs;

use App\Http\Integrations\GoFlightIntegration\Requests\OneWayDirectFlightCalendarRequest;
use App\Models\Airport;
use App\Models\DirectFlightAvailability;
use App\Models\PackageConfig;
use DateTime;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
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

        $processedMonths = DirectFlightAvailability::where('destination_origin_id', $this->packageConfig->destination_origin_id)
            ->whereBetween('date', [$startDate->format('Y-m-01'), $endDate->format('Y-m-t')])
            ->selectRaw('DATE_FORMAT(date, "%Y-%m") as `year_month`')
            ->distinct()
            ->pluck('year_month')
            ->toArray();

        while ($startDate < $endDate) {
            $yearMonth = $startDate->format('Y-m');

            if (in_array($yearMonth, $processedMonths)) {
                $logger->info("Skipping month $yearMonth for PackageConfig ID {$this->packageConfig->id} (Already has data)");
                $startDate->modify('first day of next month');

                continue;
            }

            $logger->info("Checking flights for PackageConfig ID {$this->packageConfig->id} - Month $yearMonth");

            // Kontrollo të dyja anët (outbound dhe return) dhe ruaj rezultatin
            $outboundSuccess = $this->checkFlights($originAirport, $destinationAirport, $yearMonth, $this->packageConfig->destination_origin_id, false);
            $returnSuccess = $this->checkFlights($destinationAirport, $originAirport, $yearMonth, $this->packageConfig->destination_origin_id, true);

            // Log rezultatin për debugging
            if ($outboundSuccess || $returnSuccess) {
                $logger->info("Successfully processed month {$yearMonth} - Outbound: " . ($outboundSuccess ? 'YES' : 'NO') . ", Return: " . ($returnSuccess ? 'YES' : 'NO'));
            } else {
                $logger->warning("Failed to process month {$yearMonth} - Both outbound and return failed or no flights available.");
            }

            $startDate->modify('first day of next month');
        }

        $logger->info('|===========================================================|');
        $logger->info("|FINISHED $origin->name - $destination->name SUCCESSFULLY|");
        $logger->info('|===========================================================|');
    }

    protected function checkFlights($originAirport, $destinationAirport, $yearMonth, $destinationOriginId, $isReturnFlight): bool
    {
        $logger = Log::channel('directdates');
        $flightType = $isReturnFlight ? 'RETURN' : 'OUTBOUND';

        if (! $originAirport || ! $destinationAirport) {
            $logger->warning("Missing airport data for {$flightType} - From: {$originAirport?->id}, To: {$destinationAirport?->id}");
            return false;
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

            if (empty($grids)) {
                $logger->warning("No grid data returned for {$flightType} - Month: {$yearMonth}");
                return false;
            }

            $datesAdded = 0;
            foreach ($grids as $index => $grid) {
                if (isset($grid['DirectOutboundAvailable']) && $grid['DirectOutboundAvailable'] === true) {
                    [$year, $month] = explode('-', $yearMonth);
                    $date = new DateTime;
                    $date->setDate((int) $year, (int) $month, $index + 1);

                    DirectFlightAvailability::updateOrCreate([
                        'date' => $date->format('Y-m-d'),
                        'destination_origin_id' => $destinationOriginId,
                        'is_return_flight' => $isReturnFlight,
                    ]);

                    $datesAdded++;
                    $logger->info("Added {$flightType} flight - Date: {$date->format('Y-m-d')}");
                }
            }

            if ($datesAdded > 0) {
                $logger->info("Successfully added {$datesAdded} {$flightType} flight dates for month {$yearMonth}");
                return true;
            } else {
                $logger->info("No direct flights available for {$flightType} - Month: {$yearMonth}");
                return false; // Nuk ka fluturime, por kjo nuk është error - thjesht nuk ka disponueshmëri
            }
        } catch (Exception $e) {
            $logger->error("!!!ERROR!!! Failed to check {$flightType} flights for month {$yearMonth}");
            $logger->error("Error message: {$e->getMessage()}");

            DB::table('failed_availability_checks')->insert([
                'origin_airport_id' => $originAirport->id,
                'destination_airport_id' => $destinationAirport->id,
                'year_month' => $yearMonth,
                'is_return_flight' => $isReturnFlight,
                'destination_origin_id' => $destinationOriginId,
                'error_message' => $e->getMessage(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return false;
        }
    }
}
