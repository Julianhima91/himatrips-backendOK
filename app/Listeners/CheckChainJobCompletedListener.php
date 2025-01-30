<?php

namespace App\Listeners;

use App\Events\CheckChainJobCompletedEvent;
use App\Models\Ad;
use App\Models\AdConfigCsv;
use App\Models\Holiday;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class CheckChainJobCompletedListener
{
    public function __construct() {}

    /**
     * Handle the event.
     */
    public function handle(CheckChainJobCompletedEvent $event): void
    {
        $batchIds = Cache::get('create_csv');
        $currentCsvBatchIds = Cache::get('current_csv_batch_ids');
        $currentCsvBatchIds[] = (string) $event->batchId;
        Cache::put('current_csv_batch_ids', $currentCsvBatchIds, 90);
        //
        //        //todo when count of both arrays is the same, then proceed to sort them
        if (isset($currentCsvBatchIds) && isset($batchIds) && count($batchIds) === count($currentCsvBatchIds)) {
            sort($batchIds);
            sort($currentCsvBatchIds);
        }

        //        Log::error($batchIds, $currentCsvBatchIds);
        //                Log::error('comparison result: '.var_export($batchIds == $currentCsvBatchIds, true));
        //
        if ($batchIds === $currentCsvBatchIds) {

            $ads = Ad::query()
                ->whereIn('batch_id', $batchIds)
                ->orderBy('total_price', 'asc')
                ->get();
            //
            [$csvPath, $adConfigId] = $this->exportAdsToCsv($ads);

            AdConfigCsv::create([
                'ad_config_id' => $adConfigId,
                'file_path' => $csvPath,
            ]);
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
            //                    $ad->outboundFlight()->delete();
            //                    $ad->inboundFlight()->delete();
            //                }
            //
            //                Ad::query()
            //                    ->whereIn('batch_id', $batchIds)
            //                    ->where('id', '!=', $smallestPriceAd->id)
            //                    ->delete();
            //            }
            //
            //            Cache::forget('batch_ids');
            //            Cache::forget('current_batch_ids');
        }
    }

    public function exportAdsToCsv($ads)
    {
        Log::info('Exporting ...');
        Log::info(count($ads));

        $adConfig = $ads[0]->ad_config_id;
        $filename = 'ads_holiday_export_'.now()->format('YmdHis').'.csv';

        $directory = storage_path('app/public/offers');
        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $filepath = $directory.'/'.$filename;

        $file = fopen($filepath, 'w');

        fputcsv($file, [
            'ID',
            'Destination ID',
            'Batch ID',
            'Total Price',
            'Title',
//            'images with url, tags',
//            'type (tags of destination)',
            'Description',
        ]);

        foreach ($ads as $ad) {
            Log::warning($ad->id);
            $nights = $ad->hotelData->number_of_nights;
            $pricePerPerson = $ad->total_price / 2;
            $departureDate = $ad->outboundFlight->departure->format('d/m');
            $arrivalDate = $ad->inboundFlight->departure->format('d/m');
            $origin = $ad->adConfig->origin->name;
            $destination = $ad->destination;
            $boardOptions = $ad->hotelData->cheapestOffer->first()->room_basis;
            //get holiday so we can include it in the description/title

            $departureFormatted = str_replace('/', '-', $departureDate);
            $arrivalFormatted = str_replace('/', '-', $arrivalDate);

            $holiday = Holiday::query()
                ->where(function ($query) use ($departureFormatted, $arrivalFormatted) {
                    $query->whereRaw('STRCMP(?, day) <= 0', [$departureFormatted])
                        ->orWhereRaw('STRCMP(?, day) >= 0', [$arrivalFormatted]);
                })
                ->where(function ($query) use ($departureFormatted, $arrivalFormatted) {
                    $query->whereRaw('RIGHT(day, 2) = ?', [substr($departureFormatted, -2)])
                        ->orWhereRaw('RIGHT(day, 2) = ?', [substr($arrivalFormatted, -2)]);
                })
                ->first();

            $description = "
❣️ $holiday->name";

            if ($boardOptions == 'AI') {
                $description .= ' All Inclusive';
            }

            $description .= " ne $origin Nga $destination->name ❣️";

            $body = "
✈️ $departureDate - $arrivalDate ➥ $pricePerPerson €/P $nights Nete
Te Perfshira :
✅ Bilete Vajtje - Ardhje nga $origin
✅ Cante 10 Kg
✅ Taksa Aeroportuale
✅ Akomodim ne Hotel
✅ Me Mengjes
------- ⭐ Whatsaap ose Instagram Per Info ⭐-------
📫 Zyrat Tona
📍 Tiranë , Tek kryqëzimi i Rrugës Muhamet Gjollesha me Myslym Shyrin.
📞 +355694767427
📍 Durres : Rruga Aleksander Goga , Perballe shkolles Eftali Koci
📞 +355699868907";

            fputcsv($file, [
                $ad->id,
                $destination->id,
                $ad->batch_id,
                $ad->total_price,
                $description,
                $body,
            ]);
        }

        fclose($file);

        return [$filename, $adConfig];
    }
}
