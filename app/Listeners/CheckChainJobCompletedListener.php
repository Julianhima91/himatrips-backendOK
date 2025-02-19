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
        Log::error('INSIDE HOLIDAY LISTENER');
        $batchIds = Cache::get("$event->adConfigId:create_csv");
        $currentCsvBatchIds = Cache::get("$event->adConfigId:current_csv_batch_ids");
        $currentCsvBatchIds[] = (string) $event->batchId;
        Cache::put("$event->adConfigId:current_csv_batch_ids", $currentCsvBatchIds, 90);
        //
        //        //todo when count of both arrays is the same, then proceed to sort them
        if (isset($currentCsvBatchIds) && isset($batchIds) && count($batchIds) === count($currentCsvBatchIds)) {
            sort($batchIds);
            sort($currentCsvBatchIds);
        }

        Log::info('HOLIDAY');
        Log::info($batchIds);
        Log::info($currentCsvBatchIds);
        //        Log::error($batchIds, $currentCsvBatchIds);
        //                Log::error('comparison result: '.var_export($batchIds == $currentCsvBatchIds, true));
        //
        if ($batchIds === $currentCsvBatchIds) {
            Log::info('WE ARE INSIDE (HOLIDAY) !!!!!!!!!!!!!WOOHOOOOOO');

            $ads = Ad::query()
                ->whereIn('batch_id', $batchIds)
                ->orderBy('total_price', 'asc')
                ->get();
            //
            [$csvPath, $adConfigId] = $this->exportAdsToCsv($ads);

            AdConfigCsv::updateOrCreate([
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
            Cache::forget("$event->adConfigId:batch_ids");
            Cache::forget("$event->adConfigId:current_batch_ids");
        }
    }

    public function exportAdsToCsv($ads)
    {
        $totalAds = count($ads);
        $adConfig = $ads[0]->ad_config_id;
        Log::info("Exporting $totalAds ads for holiday... Ad config id: $adConfig");

        $filename = 'ads_holiday_export_'.$adConfig.'.csv';

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
            'Description',
            'Photos',
            'Videos',
            'Destination Tags',
            'Address',
            'City',
            'Country',
            'Latitude',
            'Longitude',
            'Neighborhood',
            'Product Tag',
            'Price Change',
            'URL',
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

            $photos = $destination->destinationPhotos->filter(function ($file) {
                return ! str_ends_with($file->file_path, '.mp4');
            })->map(function ($photo) {
                return [
                    'url' => url('/storage/'.$photo->file_path),
                    'tags' => implode(', ', $photo->tags->pluck('name')->toArray()),
                ];
            });

            $photoData = $photos->map(function ($photo) {
                return $photo['url'].' '.$photo['tags'];
            })->implode(', ');

            $videos = $destination->destinationPhotos->filter(function ($file) {
                return str_ends_with($file->file_path, '.mp4'); // Only videos
            })->map(function ($video) {
                return [
                    'url' => url('/storage/'.$video->file_path),
                    'tags' => implode(', ', $video->tags->pluck('name')->toArray()),
                ];
            });

            $videoData = $videos->map(function ($video) {
                return $video['url'].' '.$video['tags'];
            })->implode(', ');

            $destinationTags = implode(', ', $destination->tags->pluck('name')->toArray());

            $mostExpensiveOffer = $ad->hotelData->mostExpensiveOffer;
            $cheapestOffer = $ad->hotelData->cheapestOffer;

            $priceDiff = $cheapestOffer[0]->price - $mostExpensiveOffer[0]->price;

            $requestData = json_decode($ad->request_data, true);

            $originName = strtolower($origin);
            $destinationName = strtolower($destination->name);
            $url = env('FRONT_URL')."/search-$originName-to-$destinationName/?query=".base64_encode(http_build_query([
                'nights' => $requestData['nights'],
                'checkin_date' => $requestData['date'],
                'origin_id' => $requestData['origin_id'],
                'destination_id' => $requestData['destination_id'],
                'rooms' => $requestData['rooms'],
                'page' => 1,
            ]));

            fputcsv($file, [
                $ad->id,
                $destination->id,
                $ad->batch_id,
                $ad->total_price,
                $description,
                $body,
                $photoData,
                $videoData,
                $destinationTags,
                $destination->address,
                $destination->city,
                $destination->country,
                $destination->latitude,
                $destination->longitude,
                $destination->neighborhood,
                $ad->offer_category,
                $priceDiff,
                $url,
            ]);
        }

        fclose($file);

        return [$filename, $adConfig];
    }
}
