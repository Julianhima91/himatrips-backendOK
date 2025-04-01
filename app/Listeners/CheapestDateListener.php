<?php

namespace App\Listeners;

use App\Events\CheapestDateEvent;
use App\Models\Ad;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CheapestDateListener
{
    public function __construct() {}

    /**
     * Handle the event.
     */
    public function handle(CheapestDateEvent $event): void
    {
        $batchIds = Cache::get("$event->adConfigId:batch_ids");
        $currentBatchIds = Cache::get("$event->adConfigId:current_batch_ids");
        if ($event->batchId) {
            $currentBatchIds[] = (string) $event->batchId;
            Cache::put("$event->adConfigId:current_batch_ids", $currentBatchIds);
        }

        $formattedHolidays = array_map(fn ($date) => $date->format('Y-m-d'), $event->holidays);
        $holidaysMap = [$event->destinationId => array_unique($formattedHolidays)];
        Cache::put("$event->adConfigId:current_holidays", $holidaysMap);

        $a = Cache::get("$event->adConfigId:current_holidays");
        Log::info('Cached Holidays:', $a);

        //todo when count of both arrays is the same, then proceed to sort them
        if (isset($currentBatchIds) && isset($batchIds) && count($batchIds) === count($currentBatchIds)) {
            sort($batchIds);
            sort($currentBatchIds);
        }

        Log::error('comparison result: '.var_export($batchIds == $currentBatchIds, true));

        if ($batchIds === $currentBatchIds) {

            Log::warning('INSIDE CHEAPEST DATE DELETION');
            $holidaysMap = Cache::get("$event->adConfigId:current_holidays", []);
            $holidays = $holidaysMap[$event->destinationId] ?? [];

            $adConfigId = $event->adConfigId;
            $query = Ad::select('ads.*')
                ->leftJoin('flight_data as f', 'f.id', '=', 'ads.outbound_flight_id')
                ->leftJoin('flight_data as f2', 'f2.id', '=', 'ads.inbound_flight_id')
                ->where('ads.ad_config_id', $adConfigId)
                ->where('ads.offer_category', 'holiday')
                ->whereIn('batch_id', $batchIds)
                ->where(function ($q) use ($holidays, $adConfigId) {
                    foreach ($holidays as $holiday) {
                        $q->orWhere(function ($subQuery) use ($holiday, $adConfigId) {
                            $subQuery->whereRaw('DATE(?) BETWEEN DATE(f.departure) AND DATE(f2.arrival)', [$holiday])
                                ->whereRaw('ads.total_price = (
                        SELECT MIN(ads_inner.total_price)
                        FROM ads as ads_inner
                        LEFT JOIN flight_data as f3 ON f3.id = ads_inner.outbound_flight_id
                        LEFT JOIN flight_data as f4 ON f4.id = ads_inner.inbound_flight_id
                        WHERE ads_inner.destination_id = ads.destination_id
                          AND ads_inner.ad_config_id = ?
                          AND ads_inner.offer_category = "holiday"
                          AND DATE(?) BETWEEN DATE(f3.departure) AND DATE(f4.arrival)
                        )', [$adConfigId, $holiday]);
                        });
                    }
                })
                ->pluck('ads.id');

            Log::info('CCCCCCC Selected Ad IDs:', ['ad_ids' => $query->toArray()]);

            Ad::where([
                ['ad_config_id', $event->adConfigId],
                ['offer_category', 'holiday'],
            ])
                ->whereNotIn('id', $query)
                ->delete();

            //            $ads = Ad::query()
            //                ->whereIn('batch_id', $batchIds)
            //                ->orderBy('total_price', 'asc')
            //                ->get();
            //
            //            if ($ads->isNotEmpty()) {
            //                $smallestPriceAd = $ads->first();
            //
            //                $adsToDelete = Ad::query()
            //                    ->whereIn('batch_id', $batchIds)
            //                    ->where('id', '!=', $smallestPriceAd->id)
            //                    ->get();
            //
            //                foreach ($adsToDelete as $ad) {
            //                    $ad->hotelData()->delete();
            //                }
            //
            //                Ad::query()
            //                    ->whereIn('batch_id', $batchIds)
            //                    ->where('id', '!=', $smallestPriceAd->id)
            //                    ->delete();
            //            }

            Cache::forget("$event->adConfigId:batch_ids");
            Cache::forget("$event->adConfigId:current_batch_ids");
        }
    }
}
