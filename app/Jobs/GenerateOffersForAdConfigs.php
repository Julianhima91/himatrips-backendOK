<?php

namespace App\Jobs;

use App\Enums\OfferCategoryEnum;
use App\Models\AdConfig;
use App\Models\Airport;
use App\Models\Destination;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerateOffersForAdConfigs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $adConfigId;

    public function __construct($adConfigId = null)
    {
        $this->adConfigId = $adConfigId;
    }

    public function handle(): void
    {
        if (! $this->adConfigId) {
            $adConfig = AdConfig::first();
            if (! $adConfig) {
                Log::info('No ad configurations found.');

                return;
            }
        } else {
            $adConfig = AdConfig::find($this->adConfigId);

            if (! $adConfig) {
                Log::info('No ad configuration found for ID: '.$this->adConfigId);

                return;
            }
        }
        //do logic here
        $this->adConfigId = $adConfig->id;
        $this->generateOffers($adConfig);

        $this->dispatchNextJob();
    }

    private function dispatchNextJob()
    {
        $nextAdConfig = AdConfig::where('id', '>', $this->adConfigId)
            ->orderBy('id')
            ->first();

        if ($nextAdConfig) {
            Log::info('Next Ad config ID: '.$nextAdConfig->id);
            GenerateOffersForAdConfigs::dispatch($nextAdConfig->id)->delay(now()->addSeconds(3));
        } else {
            Log::info('No more ad configurations found after ID: '.$this->adConfigId);
        }
    }

    private function generateOffers(AdConfig $adConfig)
    {
        Log::info('Generating offers for AdConfig ID: '.$adConfig->id);

        foreach ($adConfig->airports as $airport) {
            foreach ($adConfig->destinations as $destination) {
                $destinationAirport = Airport::query()->whereHas('destinations', function ($query) use ($destination) {
                    $query->where('destination_id', $destination->id);
                })->first();

                if (! $destination->is_active) {
                    Log::info('Skipping inactive destination ID: '.$destination->id);

                    continue;
                }

                foreach ($destination->offer_category as $offerCategory) {
                    match ($offerCategory) {
                        OfferCategoryEnum::HOLIDAY->value => $this->createHolidayOffer($adConfig, $airport, $destination, $destinationAirport),
                        OfferCategoryEnum::ECONOMIC->value => $this->createEconomicOffer($adConfig, $airport, $destination, $destinationAirport),
                        OfferCategoryEnum::WEEKEND->value => $this->createWeekendOffer($adConfig, $airport, $destination, $destinationAirport),
                        default => Log::warning("Unknown offer category: {$offerCategory}"),
                    };
                }
            }
        }
    }

    private function createHolidayOffer(AdConfig $adConfig, Airport $airport, Destination $destination, Airport $destinationAirport)
    {
        $today = now();
        $threeMonthsFromNow = now()->addMonths(3);

        $holidays = $destination
            ->getRelationValue('country')
            ->holidays()
            ->get()
            ->filter(function ($holiday) use ($today, $threeMonthsFromNow) {
                [$day, $month] = explode('-', $holiday->day);

                $holidayDate = now()->startOfYear()->setMonth($month)->setDay($day);

                return $holidayDate->between($today, $threeMonthsFromNow);
            })
            ->pluck('day')
            ->toArray();

        $requests[] = [];

        foreach ($holidays as $holiday) {
            [$holidayDay, $holidayMonth] = explode('-', $holiday);

            $holidayDate = now()->startOfYear()->setMonth($holidayMonth)->setDay($holidayDay);

            $minNights = $destination->ad_min_nights;
            $maxNights = $destination->ad_max_nights;

            for ($nights = $minNights; $nights <= $maxNights; $nights++) {
                for ($startOffset = 1; $startOffset < $nights - 1; $startOffset++) {
                    $startDate = $holidayDate->copy()->subDays($startOffset);
                    $endDate = $startDate->copy()->addDays($nights - 1);

                    if ($startDate->between($today, $threeMonthsFromNow) && $endDate->between($today, $threeMonthsFromNow)) {
                        $batchId = Str::orderedUuid();

                        $requests[] = [
                            'origin_airport' => $airport,
                            //                            'origin_airport' => $airport->id,
                            'destination_airport' => $destinationAirport,
                            //                            'destination_airport' => $destinationAirport->id,
                            'date' => $startDate->toDateString(),
                            'nights' => $nights,
                            'return_date' => $endDate->toDateString(),
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
                            //                            'holiday' => $holidayDate->toDateString(),
                            //                            'type' => OfferCategoryEnum::HOLIDAY->value,
                        ];
                    }
                }
            }
        }

        $batchIds = array_map('strval', array_column($requests, 'batch_id'));
        //        Log::warning(count($requests) . ' requests created.');

        foreach ($requests as $request) {
            if (! empty($request)) {
                //                Log::info('Offer data: ', $request);

                Bus::chain([
                    new ProcessFlightsJob($request, $this->adConfigId),
                    new LiveSearchHotels(
                        $request['date'],
                        $request['nights'],
                        $request['destination_id'],
                        2, // Adults
                        0, // Children
                        0, // Infants
                        $request['rooms'],
                        $request['batch_id']
                    ),
                    new ProcessResponsesJob($request['batch_id'], $request, $adConfig, $batchIds),
                ])->dispatch();
            }
        }
        //
        //        Bus::chain([
        //            new CheapestDateJob($requests),
        //        ])->dispatch();
    }

    private function createEconomicOffer(AdConfig $adConfig, mixed $airport, mixed $destination)
    {
        //        $offerData = [
        //            'ad_config_id' => $adConfig->id,
        //            'airport_id' => $airport->id,
        //            'destination_id' => $destination->id,
        //            'type' => OfferCategoryEnum::ECONOMIC->value,
        //        ];
        //
        //        Log::info('Offer created: ', $offerData);
    }

    private function createWeekendOffer(AdConfig $adConfig, mixed $airport, mixed $destination)
    {
        //        $offerData = [
        //            'ad_config_id' => $adConfig->id,
        //            'airport_id' => $airport->id,
        //            'destination_id' => $destination->id,
        //            'type' => OfferCategoryEnum::WEEKEND->value,
        //        ];
        //
        //        Log::info('Offer created: ', $offerData);
    }
}
