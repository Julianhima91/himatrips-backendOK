<?php

namespace App\Jobs;

use App\Models\FlightData;
use App\Models\HotelData;
use App\Models\HotelOffer;
use App\Models\Package;
use App\Models\PackageConfig;
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

    private $commission;

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

        $origin = $packageConfig->destination_origin->origin;
        $destination = $packageConfig->destination_origin->destination;

        $originAirport = $origin->airports->first();
        $destinationAirport = $destination->airports->first();

        $batchId = Str::orderedUuid();
        $csv = Storage::disk('public')->get('aaaa.csv');
        //        $csv = Storage::disk('public')->get($this->filePath);
        $rows = preg_split('/\r\n|\n|\r/', trim($csv));

        \DB::beginTransaction();

        $currentFlightData = null;
        $currentHotelData = null;
        $currentFlightPrice = null;
        $cycleTotalPrices = [];
        $cycleOfferCommissions = [];

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
                    $commissionForMinOffer = $cycleOfferCommissions[$minIndex] ?? null;

                    Log::info('PACKAGE CREATION (cycle)');
                    Package::create([
                        'package_config_id' => $packageConfig->id,
                        'outbound_flight_id' => $currentFlightData->id,
                        'inbound_flight_id' => $currentFlightData->id + 1,
                        'hotel_data_id' => $currentHotelData->id,
                        'commission' => $commissionForMinOffer,
                        'total_price' => $minPrice,
                        'batch_id' => $batchId,
                    ]);

                    $currentFlightData = null;
                    $currentHotelData = null;
                    $currentFlightPrice = null;
                    $cycleTotalPrices = [];
                    $cycleOfferCommissions = [];
                }

                $currentFlightPrice = $data[5] ?? null;

                if ($data[12] == 0) {
                    $segments = json_encode([[
                        'origin' => [
                            'name' => $data[17],
                            'displayCode' => $data[18],
                        ],
                        'destination' => [
                            'name' => $data[19],
                            'displayCode' => $data[20],
                        ],
                        'arrival' => $data[21],
                        'departure' => $data[22],
                    ]]);

                    $segmentsBack = json_encode([[
                        'origin' => [
                            'name' => $data[23],
                            'displayCode' => $data[24],
                        ],
                        'destination' => [
                            'name' => $data[25],
                            'displayCode' => $data[26],
                        ],
                        'arrival' => $data[27],
                        'departure' => $data[28],
                    ]]);
                } else {
                    $outboundStops = $data[12];
                    $segmentsOut = [];
                    $currentIndex = 17;

                    for ($i = 0; $i < ($outboundStops + 1); $i++) {
                        $segmentsOut[] = [
                            'origin' => [
                                'name' => $data[$currentIndex],
                                'displayCode' => $data[$currentIndex + 1],
                            ],
                            'destination' => [
                                'name' => $data[$currentIndex + 2],
                                'displayCode' => $data[$currentIndex + 3],
                            ],
                            'arrival' => $data[$currentIndex + 4],
                            'departure' => $data[$currentIndex + 5],
                        ];
                        $currentIndex += 6;
                    }

                    $returnStops = (int) $outboundStops; // for the future, incase we need separate stop count back.
                    $segmentsBack = [];

                    for ($i = 0; $i < ($returnStops + 1); $i++) {
                        $segmentsBack[] = [
                            'origin' => [
                                'name' => $data[$currentIndex],
                                'displayCode' => $data[$currentIndex + 1],
                            ],
                            'destination' => [
                                'name' => $data[$currentIndex + 2],
                                'displayCode' => $data[$currentIndex + 3],
                            ],
                            'arrival' => $data[$currentIndex + 4],
                            'departure' => $data[$currentIndex + 5],
                        ];
                        $currentIndex += 6;
                    }

                    $segments = json_encode($segmentsOut);
                    $segmentsBack = json_encode($segmentsBack);
                }

                $outbound = FlightData::create([
                    'package_config_id' => $packageConfig->id,
                    'origin' => $originAirport->sky_id ?? null,
                    'destination' => $destinationAirport->sky_id ?? null,
                    'departure' => $data[3] ?? null,
                    'arrival' => $data[4] ?? null,
                    'price' => $data[5] ?? null,
                    'airline' => $data[6] ?? null,
                    'stop_count' => $data[7] ?? null,
                    'adults' => $data[13] ?? null,
                    'children' => $data[14] ?? null,
                    'infants' => $data[15] ?? null,
                    'extra_data' => null,
                    'segments' => $segments,
                    'all_flights' => null,
                    'return_flight' => 0,
                ]);

                FlightData::create([
                    'package_config_id' => $packageConfig->id,
                    'origin' => $destinationAirport->sky_id ?? null,
                    'destination' => $originAirport->sky_id ?? null,
                    'departure' => $data[8] ?? null,
                    'arrival' => $data[9] ?? null,
                    'price' => $data[10] ?? null,
                    'airline' => $data[11] ?? null,
                    'stop_count' => $data[12] ?? null,
                    'adults' => $data[13] ?? null,
                    'children' => $data[14] ?? null,
                    'infants' => $data[15] ?? null,
                    'extra_data' => null,
                    'segments' => $segmentsBack,
                    'all_flights' => null,
                    'return_flight' => 1,
                ]);

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
                    'price' => $data[8] ?? null,
                    'room_object' => null,
                ]);
            } elseif ($type === 'Hotel Offer') {
                $transferPrice = 0;
                foreach ($currentHotelData->hotel->transfers as $transfer) {
                    $transferPrice += $transfer->adult_price * $currentHotelData->adults;

                    if ($currentHotelData->children > 0) {
                        $transferPrice += $transfer->children_price * $currentHotelData->children;
                    }
                }

                $calculatedCommissionPercentage = ($packageConfig->commission_percentage / 100) * ($currentFlightPrice + $transferPrice + $data[3]);
                $fixedCommissionRate = $packageConfig->commission_amount;
                $commission = max($fixedCommissionRate, $calculatedCommissionPercentage);

                $totalOfferPrice = $currentFlightPrice + $data[3] + $transferPrice + $commission;

                $cycleTotalPrices[] = $totalOfferPrice;
                $cycleOfferCommissions[] = $commission;

                //                Log::info("Flight price: $currentFlightPrice");
                //                Log::info("Offer price: $data[3]");
                //                Log::info("Transfer price: $transferPrice");
                //                Log::info("Commission: $commission");

                $offer = HotelOffer::create([
                    'hotel_data_id' => $currentHotelData->id,
                    'room_basis' => $data[1] ?? null,
                    'room_type' => json_encode($data[2] ?? ''),
                    'price' => $data[3] ?? null,
                    'total_price_for_this_offer' => $totalOfferPrice,
                    'reservation_deadline' => $data[4] ?? null,
                ]);
            } else {
                Log::warning("Unknown CSV data type: {$type}");
            }
        }

        if ($currentFlightData && $currentHotelData && ! empty($cycleTotalPrices)) {
            $minPrice = min($cycleTotalPrices);
            $minIndex = array_search($minPrice, $cycleTotalPrices);
            $commissionForMinOffer = $cycleOfferCommissions[$minIndex] ?? null;

            Log::info('PACKAGE CREATION (final cycle)');
            Package::create([
                'package_config_id' => $packageConfig->id,
                'outbound_flight_id' => $currentFlightData->id,
                'inbound_flight_id' => $currentFlightData->id + 1,
                'hotel_data_id' => $currentHotelData->id,
                'commission' => $commissionForMinOffer,
                'total_price' => $minPrice,
                'batch_id' => $batchId,
            ]);
        }

        \DB::commit();

        Log::info('Successfully imported packages for package config ID: '.$this->packageConfigId);
        //        Storage::disk('public')->delete($this->filePath);
    }
}
