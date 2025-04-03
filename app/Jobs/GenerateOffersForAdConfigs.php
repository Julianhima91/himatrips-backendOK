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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerateOffersForAdConfigs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $adConfigId;

    public $type;

    public function __construct($type, $adConfigId = null)
    {
        $this->adConfigId = $adConfigId;
        $this->type = $type;
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

        Log::info("CONFIG ID: $this->adConfigId");
        Log::info("TYPE ID: $this->type");
        $this->generateOffers($adConfig);

        //        $this->dispatchNextJob();
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

                match ($this->type) {
                    OfferCategoryEnum::HOLIDAY->value => $this->createHolidayOffer($adConfig, $airport, $destination, $destinationAirport),
                    OfferCategoryEnum::ECONOMIC->value => $this->createEconomicOffer($adConfig, $airport, $destination, $destinationAirport),
                    OfferCategoryEnum::WEEKEND->value => $this->createWeekendOffer($adConfig, $airport, $destination, $destinationAirport),
                    default => Log::warning("Unknown offer type: {$this->type}"),
                };

                //                foreach ($destination->offer_category as $offerCategory) {
                //                    match ($offerCategory) {
                //                        //                        OfferCategoryEnum::HOLIDAY->value => $this->createHolidayOffer($adConfig, $airport, $destination, $destinationAirport),
                //                        //                                                OfferCategoryEnum::ECONOMIC->value => $this->createEconomicOffer($adConfig, $airport, $destination, $destinationAirport),
                ////                        OfferCategoryEnum::WEEKEND->value => $this->createWeekendOffer($adConfig, $airport, $destination, $destinationAirport),
                //                        default => Log::warning("Unknown offer category: {$offerCategory}"),
                //                    };
                //                }
            }
        }
    }

    private function createHolidayOffer(AdConfig $adConfig, Airport $airport, Destination $destination, Airport $destinationAirport)
    {
        $logger = Log::channel('holiday');

        $logger->info('Holiday Job Started', ['job' => self::class]);
        $today = now();
        $threeMonthsFromNow = now()->addMonths(3);

        $holidays = $destination
            ->getRelationValue('country')
            ->holidays()
            ->get()
            ->filter(function ($holiday) use ($today, $threeMonthsFromNow, $logger, $destination) {
                [$day, $month] = explode('-', $holiday->day);

                $logger->warning("HOLIDAY: $holiday->name - $holiday->day |||| DESTINATION: $destination->name");
                $holidayDate = now()->startOfYear()->setMonth($month)->setDay($day);

                return $holidayDate->between($today, $threeMonthsFromNow);
            })
            ->pluck('day')
            ->map(function ($holiday) {
                [$holidayDay, $holidayMonth] = explode('-', $holiday);

                return now()->startOfYear()
                    ->setMonth($holidayMonth)
                    ->setDay($holidayDay)
                    ->timezone('Europe/Amsterdam');
            })
            ->toArray();
        $destinationHolidays = $holidays;

        $requests = [];

        foreach ($holidays as $holiday) {
            //            [$holidayDay, $holidayMonth] = explode('-', $holiday);
            //
            //            $holidayDate = now()->startOfYear()->setMonth($holidayMonth)->setDay($holidayDay);

            $minNights = $destination->ad_min_nights;
            $maxNights = $destination->ad_max_nights;

            for ($nights = $minNights; $nights <= $maxNights; $nights++) {
                for ($startOffset = 1; $startOffset <= max(1, $nights - 1); $startOffset++) {
                    $startDate = $holiday->copy()->subDays($startOffset);
                    $endDate = $startDate->copy()->addDays($nights);

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
                            'category' => OfferCategoryEnum::HOLIDAY->value,
                            'holidays' => $destinationHolidays,
                        ];
                    }
                }
            }
        }

        $batchIds = array_map('strval', array_column($requests, 'batch_id'));
        //        $logger->warning(count($requests) . ' requests created.');

        foreach ($requests as $index => $request) {
            $logger->info("Generated Request nr. $index:\n".json_encode([
                'origin_airport' => $airport['codeIataAirport'] ?? $airport->codeIataAirport,
                'destination_airport' => $destinationAirport['codeIataAirport'] ?? $destinationAirport->codeIataAirport,
                'date' => $request['date'],
                'nights' => $request['nights'],
                'return_date' => $request['return_date'],
                'origin_id' => $adConfig->origin_id,
                'destination_id' => $destination->id,
                'batch_id' => $batchId,
                'category' => OfferCategoryEnum::HOLIDAY->value,
                'holidays' => $destinationHolidays,
            ], JSON_PRETTY_PRINT));

            Bus::chain([
                (new ProcessFlightsJob($request, $this->adConfigId, 'holiday'))->onQueue('holidays'),
                (new LiveSearchHotels(
                    $request['date'],
                    $request['nights'],
                    $request['destination_id'],
                    2, // Adults
                    0, // Children
                    0, // Infants
                    $request['rooms'],
                    $request['batch_id']
                ))->onQueue('holidays'),
                (new ProcessResponsesJob($request['batch_id'], $request, $adConfig, $batchIds))->onQueue('holidays'),
            ])->dispatch();

        }
        //
        //        Bus::chain([
        //            new CheapestDateJob($requests),
        //        ])->dispatch();
    }

    private function createEconomicOffer(AdConfig $adConfig, Airport $airport, Destination $destination, Airport $destinationAirport)
    {
        $logger = Log::channel('economic');

        $logger->info('Economic Job Started', ['job' => self::class]);

        $months = collect($destination->active_months)
            ->map(fn ($month) => now()->format('Y').'-'.$month)
            ->filter(fn ($month) => Carbon::parse($month)->greaterThanOrEqualTo(now()->startOfMonth()))
            ->mapWithKeys(fn ($month) => [(string) Str::orderedUuid() => $month])
            ->toArray();

        $batchIds = collect($months)->keys()->toArray();

        $logger->info($adConfig->origin->name.'-'.$destination->name);
        $logger->info($months);

        foreach ($months as $batchId => $month) {
            Bus::chain([
                (new CheckEconomicFlightJob($airport, $destinationAirport, $month, $this->adConfigId, $batchId, false))->onQueue('economic'),
                (new CheckEconomicFlightJob($airport, $destinationAirport, $month, $this->adConfigId, $batchId, true))->onQueue('economic'),
                (new ProcessEconomicResponsesJob($batchId, $this->adConfigId, $destination->ad_min_nights))->onQueue('economic'),
                (new EconomicFlightSearch($month, $airport, $destinationAirport, 2, 0, 0, $batchId, $this->adConfigId))->onQueue('economic'),
                (new EconomicHotelJob(
                    $destination->ad_min_nights,
                    $destination->id,
                    [
                        [
                            'adults' => 2,
                            'children' => 0,
                            'infants' => 0,
                        ],
                    ],
                    $batchId,
                    $month,
                    $this->adConfigId,
                ))->onQueue('economic'),
                (new TestEconomicFlights($batchId, $adConfig, $month, $adConfig->origin_id, $destination->id, $airport, $destinationAirport, $batchIds))->onQueue('economic'),
            ])->dispatch();
        }

    }

    private function createWeekendOffer(AdConfig $adConfig, Airport $airport, Destination $destination, Airport $destinationAirport)
    {
        $logger = Log::channel('weekend');

        $logger->info('Weekend Job Started', ['job' => self::class]);

        $today = now();
        //todo: change this to 3 months
        $threeMonthsFromNow = now()->addMonths(3);

        $weekends = [];
        $groupedWeekends = [];
        $requests = [];

        while ($today->lessThanOrEqualTo($threeMonthsFromNow)) {
            if ($today->isFriday()) {
                $weekendStart = $today->toDateString();
                $weekendEnd = $today->copy()->addDays(2)->toDateString(); // Sunday

                if ($today->copy()->addDays(2)->lessThanOrEqualTo($threeMonthsFromNow)) {
                    $groupedWeekends[] = [
                        'date' => $weekendStart, // Friday
                        'return_date' => $weekendEnd, // Sunday
                    ];

                    $logger->info("Weekend dates: $weekendStart - $weekendEnd");
                }
            }

            $today->addDay();
        }

        //        for ($i = 0; $i < count($weekends); $i += 2) {
        //            if (isset($weekends[$i + 1])) {
        //                //todo: maybe change it to friday-sunday?
        //                $groupedWeekends[] = [
        //                    'date' => $weekends[$i], // Saturday
        //                    'return_date' => $weekends[$i + 1], // Sunday
        //                ];
        //            }
        //        }

        //                $logger->info('Grouped Weekends: ');
        //                $logger->info(print_r($groupedWeekends, true));

        foreach ($groupedWeekends as $groupedWeekend) {
            $batchId = Str::orderedUuid();

            $requests[] = [
                'origin_airport' => $airport,
                'destination_airport' => $destinationAirport,
                'date' => $groupedWeekend['date'],
                'nights' => 2,
                'return_date' => $groupedWeekend['return_date'],
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
                'category' => OfferCategoryEnum::WEEKEND->value,
            ];
        }

        $batchIds = array_map('strval', array_column($requests, 'batch_id'));

        //todo: remove $count after testing
        //        $count = 0;
        //        $logger->warning(json_encode($requests, JSON_PRETTY_PRINT));
        foreach ($requests as $index => $request) {

            $logger->info("Generated Request nr. $index:\n".json_encode([
                'origin_airport' => $airport['codeIataAirport'] ?? $airport->codeIataAirport,
                'destination_airport' => $destinationAirport['codeIataAirport'] ?? $destinationAirport->codeIataAirport,
                'date' => $request['date'],
                'nights' => 2,
                'return_date' => $request['return_date'],
                'origin_id' => $adConfig->origin_id,
                'destination_id' => $destination->id,
                'batch_id' => $batchId,
                'category' => OfferCategoryEnum::WEEKEND->value,
            ], JSON_PRETTY_PRINT));
            $logger->info('====================================================');

            Bus::chain([
                (new ProcessFlightsJob($request, $this->adConfigId, 'weekend'))->onQueue('weekend'),
                (new LiveSearchHotels(
                    $request['date'],
                    $request['nights'],
                    $request['destination_id'],
                    2, // Adults
                    0, // Children
                    0, // Infants
                    $request['rooms'],
                    $request['batch_id']
                ))->onQueue('weekend'),
                (new ProcessWeekendResponsesJob($request['batch_id'], $request, $adConfig, $batchIds))->onQueue('weekend'),
            ])->dispatch();
        }
    }
}
