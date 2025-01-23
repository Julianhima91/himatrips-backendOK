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
        $batchIds = Cache::get('batch_ids');
        $currentBatchIds = Cache::get('current_batch_ids');
        $currentBatchIds[] = (string) $event->batchId;
        Cache::put('current_batch_ids', $currentBatchIds, 90);

        //todo when count of both arrays is the same, then proceed to sort them
        if (isset($currentBatchIds) && isset($batchIds) && count($batchIds) === count($currentBatchIds)) {
            sort($batchIds);
            sort($currentBatchIds);
        }

        //        Log::error('comparison result: '.var_export($batchIds == $currentBatchIds, true));

        if ($batchIds === $currentBatchIds) {
            $ads = Ad::query()
                ->whereIn('batch_id', $batchIds)
                ->orderBy('total_price', 'asc')
                ->get();

            if ($ads->isNotEmpty()) {
                $smallestPriceAd = $ads->first();

                $adsToDelete = Ad::query()
                    ->whereIn('batch_id', $batchIds)
                    ->where('id', '!=', $smallestPriceAd->id)
                    ->get();

                foreach ($adsToDelete as $ad) {
                    $ad->hotelData()->delete();
                    $ad->outboundFlight()->delete();
                    $ad->inboundFlight()->delete();
                }

                Ad::query()
                    ->whereIn('batch_id', $batchIds)
                    ->where('id', '!=', $smallestPriceAd->id)
                    ->delete();
            }

            Cache::forget('batch_ids');
            Cache::forget('current_batch_ids');
        }
    }
}
