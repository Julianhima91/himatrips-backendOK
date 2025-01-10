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
use Illuminate\Support\Facades\Log;

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
                if (! $destination->is_active) {
                    Log::info('Skipping inactive destination ID: '.$destination->id);

                    continue;
                }

                foreach ($destination->offer_category as $offerCategory) {

                    match ($offerCategory) {
                        OfferCategoryEnum::HOLIDAY->value => $this->createHolidayOffer($adConfig, $airport, $destination),
                        OfferCategoryEnum::ECONOMIC->value => $this->createEconomicOffer($adConfig, $airport, $destination),
                        OfferCategoryEnum::WEEKEND->value => $this->createWeekendOffer($adConfig, $airport, $destination),
                        default => Log::warning("Unknown offer category: {$offerCategory}"),
                    };
                }
            }
        }
    }

    private function createHolidayOffer(AdConfig $adConfig, Airport $airport, Destination $destination)
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

        $offers = [];
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
                        $offers[] = [
                            'ad_config_id' => $adConfig->id,
                            'airport_name' => $airport->nameAirport,
                            'destination_name' => $destination->name,
                            'type' => OfferCategoryEnum::HOLIDAY->value,
                            'start_date' => $startDate->toDateString(),
                            'end_date' => $endDate->toDateString(),
                            'holiday' => $holidayDate->toDateString(),
                        ];
                    }
                }
            }
        }

        foreach ($offers as $offerData) {
            Log::info('Offer data: ', $offerData);
        }
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
