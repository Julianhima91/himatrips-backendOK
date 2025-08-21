<?php

namespace App\Jobs;

use App\Models\FlightData;
use App\Models\HotelData;
use App\Models\HotelOffer;
use App\Models\Package;
use App\Models\PackageConfig;
use DateTime;
use DB;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportPackagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $packageConfigId;

    protected $filePath;

    private $hotelPrice;

    private $flightPrice;

    private HotelData $hotelData;

    public function __construct($packageConfigId, $filePath)
    {
        $this->packageConfigId = $packageConfigId;
        $this->filePath = $filePath;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $packageConfig = PackageConfig::find($this->packageConfigId);
        if (! $packageConfig) {
            Log::error("Package config with ID {$this->packageConfigId} not found.");

            return;
        }

        // Delete all old data for this package config
        $packages = Package::query()->where('package_config_id', $this->packageConfigId)->get();

        foreach ($packages as $package) {
            $package->hotelData()->delete();
            $package->inboundFlight()->delete();
            $package->outboundFlight()->delete();
        }

        Package::query()->where('package_config_id', $this->packageConfigId)->forceDelete();

        $origin = $packageConfig->destination_origin->origin;
        $destination = $packageConfig->destination_origin->destination;

        $originAirport = $origin->airports->first();
        $destinationAirport = $destination->airports->first();

        $batchId = Str::orderedUuid();
        //        $csv = Storage::disk('public')->get('aaaa.csv');
        $csv = Storage::disk('public')->get($this->filePath);
        $rows = preg_split('/\r\n|\n|\r/', trim($csv));

        DB::beginTransaction();

        $currentFlightData = null;
        $currentHotelData = null;
        $currentFlightPrice = null;
        $cycleTotalPrices = [];
        $dateCombinations = [];
        $roomCount = 0;

        foreach ($rows as $row) {
            if (trim($row) === '') {
                continue;
            }

            $data = str_getcsv($row);
            $type = $data[0] ?? null;
            if (! $type) {
                continue;
            }

            if ($type === 'Flight Data') {
                if ($currentFlightData && $currentHotelData && ! empty($cycleTotalPrices)) {
                    $minPrice = min($cycleTotalPrices);
                    $minIndex = array_search($minPrice, $cycleTotalPrices);
                    //                    $commissionForMinOffer = $cycleOfferCommissions[$minIndex] ?? null;

                    Log::error('create package');
                    Package::create([
                        'package_config_id' => $packageConfig->id,
                        'outbound_flight_id' => $currentFlightData->id,
                        'inbound_flight_id' => $currentFlightData->id + 1,
                        'hotel_data_id' => $currentHotelData->id,
                        'commission' => 0,
                        'total_price' => $minPrice,
                        'batch_id' => $batchId,
                        'extra_data' => ['transfers' => $transfers],
                    ]);

                    $currentFlightData = null;
                    $currentHotelData = null;
                    $currentFlightPrice = null;
                    $cycleTotalPrices = [];
                    $cycleOfferCommissions = [];
                }

                $currentFlightPrice = $data[5] ?? null;

                $segments = json_encode([[
                    'origin' => [
                        'name' => $data[8],
                        'displayCode' => $data[9],
                    ],
                    'destination' => [
                        'name' => $data[10],
                        'displayCode' => $data[11],
                    ],
                    'arrival' => $data[2],
                    'departure' => $data[1],
                ]]);

                $segmentsBack = json_encode([[
                    'origin' => [
                        'name' => $data[10],
                        'displayCode' => $data[11],
                    ],
                    'destination' => [
                        'name' => $data[8],
                        'displayCode' => $data[9],
                    ],
                    'arrival' => $data[4],
                    'departure' => $data[3],
                ]]);

                $outbound = FlightData::create([
                    'package_config_id' => $packageConfig->id,
                    'origin' => $originAirport->sky_id ?? null,
                    'destination' => $destinationAirport->sky_id ?? null,
                    'departure' => $data[1] ?? null,
                    'arrival' => $data[2] ?? null,
                    'price' => 0,
                    'airline' => 'charter',
                    'stop_count' => 0,
                    'adults' => $data[5] ?? null,
                    'children' => $data[6] ?? null,
                    'infants' => $data[7] ?? null,
                    'extra_data' => null,
                    'segments' => $segments,
                    'all_flights' => null,
                    'return_flight' => 0,
                ]);

                FlightData::create([
                    'package_config_id' => $packageConfig->id,
                    'origin' => $destinationAirport->sky_id ?? null,
                    'destination' => $originAirport->sky_id ?? null,
                    'departure' => $data[3] ?? null,
                    'arrival' => $data[4] ?? null,
                    'price' => 0,
                    'airline' => 'charter',
                    'stop_count' => 0,
                    'adults' => $data[5] ?? null,
                    'children' => $data[6] ?? null,
                    'infants' => $data[7] ?? null,
                    'extra_data' => null,
                    'segments' => $segmentsBack,
                    'all_flights' => null,
                    'return_flight' => 1,
                ]);

                $date = new DateTime($data[1]);
                $formattedDate = $date->format('Y-m-d');

                $returnDate = new DateTime($data[3]);
                $formattedReturnDate = $returnDate->format('Y-m-d');

                $dateCombinations[] = [
                    'departure_date' => $formattedDate,
                    'return_date' => $formattedReturnDate,
                ];

                $currentFlightData = $outbound;
            } elseif ($type === 'Hotel Data') {
                $currentHotelData = HotelData::create([
                    'package_config_id' => $packageConfig->id,
                    'hotel_id' => $data[1] ?? null,
                    'check_in_date' => $data[2] ?? null,
                    'number_of_nights' => $data[3] ?? null,
                    'room_count' => $data[4] ?? null,
                    'adults' => $data[5] ?? null,
                    'children' => $data[6] ?? null,
                    'infants' => $data[7] ?? null,
                    'price' => 0,
                    'room_object' => null,
                ]);

                $roomCount = $data[4];
                $transfers = [];

                $transfers[] = [
                    'description' => $data[8],
                ];
            } elseif ($type === 'Hotel Offer') {
                if ($roomCount > 1) {
                    $roomTypes = explode('/', $data[2]);

                    $roomType = json_encode($roomTypes);
                } else {
                    $roomType = json_encode($data[2] ?? '');
                }

                $offer = HotelOffer::create([
                    'hotel_data_id' => $currentHotelData->id,
                    'room_basis' => $data[1] ?? null,
                    'room_type' => $roomType,
                    'price' => $data[3] ?? null,
                    'total_price_for_this_offer' => $data[3] ?? null,
                    'reservation_deadline' => $data[4] ?? null,
                ]);

                $cycleTotalPrices[] = $data[3];
            } else {
                Log::warning("Unknown CSV data type: {$type}");
            }
        }

        if ($currentFlightData && $currentHotelData) {
            $minPrice = min($cycleTotalPrices);
            //            $minIndex = array_search($minPrice, $cycleTotalPrices);
            //            $commissionForMinOffer = $cycleOfferCommissions[$minIndex] ?? null;
            Log::error('create package');

            Log::info('PACKAGE CREATION (final cycle)');
            Package::create([
                'package_config_id' => $packageConfig->id,
                'outbound_flight_id' => $currentFlightData->id,
                'inbound_flight_id' => $currentFlightData->id + 1,
                'hotel_data_id' => $currentHotelData->id,
                'commission' => 0,
                'total_price' => $minPrice,
                'batch_id' => $batchId,
                'extra_data' => ['transfers' => $transfers],
            ]);
        }

        $packageConfig->update([
            'manual_date_combination' => $dateCombinations,
        ]);
        DB::commit();

        Log::info('Successfully imported packages for package config ID: '.$this->packageConfigId);
        Storage::disk('public')->delete($this->filePath);
    }
}
