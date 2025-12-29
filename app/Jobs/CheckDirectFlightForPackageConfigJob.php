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
use Illuminate\Support\Carbon;
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
            $lastProcessedMonth = $packageConfig->last_processed_month;

            if ($lastProcessedMonth) {
                $yearMonthDate = Carbon::createFromFormat('Y-m', $yearMonth);
                $lastProcessedMonthDate = Carbon::createFromFormat('Y-m', $lastProcessedMonth);

                if ($yearMonthDate->greaterThan($lastProcessedMonthDate)) {
                    Log::info("Calling API for year month {$yearMonth} - PackageConfig ID: {$packageConfig->id}");

                    // Kontrollo të dyja anët (outbound dhe return) dhe ruaj rezultatin
                    $outboundSuccess = $this->checkFlights($originAirport, $destinationAirport, $yearMonth, $packageConfig->destination_origin_id, false);
                    $returnSuccess = $this->checkFlights($destinationAirport, $originAirport, $yearMonth, $packageConfig->destination_origin_id, true);

                    // Ruaj last_processed_month VETËM nëse të dyja anët janë të suksesshme
                    // Ose nëse të paktën njëra anë ka të dhëna (p.sh. nëse return nuk ka fluturime, por outbound ka)
                    if ($outboundSuccess || $returnSuccess) {
                        $packageConfig->last_processed_month = $yearMonth;
                        $packageConfig->save();
                        Log::info("Successfully processed month {$yearMonth} - Outbound: " . ($outboundSuccess ? 'YES' : 'NO') . ", Return: " . ($returnSuccess ? 'YES' : 'NO'));
                    } else {
                        Log::warning("Failed to process month {$yearMonth} - Both outbound and return failed. Will retry next time.");
                    }
                } else {
                    Log::info("Skipping ... Latest processed month for package: {$packageConfig->id} is {$lastProcessedMonth}. Current year-month: {$yearMonth}");
                }
            } else {
                Log::info('No last processed month found, calling API for year month '.$yearMonth);

                // Kontrollo të dyja anët (outbound dhe return) dhe ruaj rezultatin
                $outboundSuccess = $this->checkFlights($originAirport, $destinationAirport, $yearMonth, $packageConfig->destination_origin_id, false);
                $returnSuccess = $this->checkFlights($destinationAirport, $originAirport, $yearMonth, $packageConfig->destination_origin_id, true);

                // Ruaj last_processed_month VETËM nëse të dyja anët janë të suksesshme ose të paktën njëra ka të dhëna
                if ($outboundSuccess || $returnSuccess) {
                    $packageConfig->last_processed_month = $yearMonth;
                    $packageConfig->save();
                    Log::info("Successfully processed month {$yearMonth} - Outbound: " . ($outboundSuccess ? 'YES' : 'NO') . ", Return: " . ($returnSuccess ? 'YES' : 'NO'));
                } else {
                    Log::warning("Failed to process month {$yearMonth} - Both outbound and return failed. Will retry next time.");
                }
            }

            $startDate->modify('first day of next month');
        }

        $this->dispatchNextJob();
    }

    private function dispatchNextJob()
    {
        $nextPackageConfig = PackageConfig::where('id', '>', $this->packageConfigId)
            ->orderBy('id')
            ->first();

        if ($nextPackageConfig) {
            Log::info('Next PACKAGE ID: '.$nextPackageConfig->id);
            CheckDirectFlightForPackageConfigJob::dispatch($nextPackageConfig->id)->delay(now()->addSeconds(3));
        } else {
            Log::info('No more package configurations found after ID: '.$this->packageConfigId);
        }
    }

    private function checkFlights($originAirport, $destinationAirport, $yearMonth, $destinationOriginId, $isReturnFlight): bool
    {
        $flightType = $isReturnFlight ? 'RETURN' : 'OUTBOUND';
        
        // Kontrollo nëse ka airport data
        if (!$originAirport || !$destinationAirport) {
            Log::warning("Missing airport data for {$flightType} - From: {$originAirport?->id}, To: {$destinationAirport?->id}");
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
                Log::warning("No grid data returned for {$flightType} - Month: {$yearMonth}");
                return false;
            }

            $datesAdded = 0;
            foreach ($grids as $index => $grid) {
                if (isset($grid['DirectOutboundAvailable']) && $grid['DirectOutboundAvailable'] === true) {
                    [$year, $month] = explode('-', $yearMonth);
                    $date = new DateTime;
                    $date->setDate((int) $year, (int) $month, $index + 1);

                    $directFlightAvailability = DirectFlightAvailability::updateOrCreate([
                        'date' => $date->format('Y-m-d'),
                        'destination_origin_id' => $destinationOriginId,
                        'is_return_flight' => $isReturnFlight,
                    ]);

                    $datesAdded++;
                    Log::info("Added {$flightType} flight - Date: {$date->format('Y-m-d')}, ID: {$directFlightAvailability->id}");
                }
            }

            if ($datesAdded > 0) {
                Log::info("Successfully added {$datesAdded} {$flightType} flight dates for month {$yearMonth}");
                return true;
            } else {
                Log::info("No direct flights available for {$flightType} - Month: {$yearMonth}");
                return false; // Nuk ka fluturime, por kjo nuk është error - thjesht nuk ka disponueshmëri
            }
        } catch (Exception $e) {
            Log::error("!!!ERROR!!! Failed to check {$flightType} flights for month {$yearMonth}");
            Log::error("Error message: {$e->getMessage()}");
            Log::error("Stack trace: {$e->getTraceAsString()}");
            return false;
        }
    }
}
