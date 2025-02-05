<?php

namespace App\Listeners;

use App\Models\Ad;
use App\Models\AdConfigCsv;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class CheckChainWeekendJobCompletedListener
{
    public function __construct() {}

    public function handle(object $event): void
    {
        //        Log::error('INSIDE LISTENER');
        $batchIds = Cache::get('create_csv');
        $currentCsvBatchIds = Cache::get('current_csv_batch_ids');
        if ($event->batchId) {
            $currentCsvBatchIds[] = (string) $event->batchId;
            Cache::put('current_csv_batch_ids', $currentCsvBatchIds, 90);
        }

        //todo when count of both arrays is the same, then proceed to sort them
        if (isset($currentCsvBatchIds) && isset($batchIds) && count($batchIds) === count($currentCsvBatchIds)) {
            sort($batchIds);
            sort($currentCsvBatchIds);
        }

        //        Log::info($batchIds);
        //        Log::info($currentCsvBatchIds);
        if ($batchIds === $currentCsvBatchIds) {
            Log::info('WE ARE INSIDE!!!!!!!!!!!!!WOOHOOOOOO');

            Cache::forget('create_csv');
            Cache::forget('current_csv_batch_ids');

            foreach ($batchIds as $batchId) {
                $cheapestAd = Ad::where('batch_id', $batchId)
                    ->orderBy('total_price', 'asc')
                    ->first();

                Ad::where('batch_id', $batchId)
                    ->where('id', '!=', optional($cheapestAd)->id)
                    ->delete();
            }

            $ads = Ad::query()
                ->whereIn('batch_id', $batchIds)
                ->orderBy('total_price', 'asc')
                ->get();

            [$csvPath, $adConfigId] = $this->exportAdsToCsv($ads);

            AdConfigCsv::create([
                'ad_config_id' => $adConfigId,
                'file_path' => $csvPath,
            ]);
        }
    }

    public function exportAdsToCsv($ads)
    {
        Log::info('Exporting ...');
        Log::info(count($ads));

        $adConfig = $ads[0]->ad_config_id;
        $filename = 'ads_weekend_export_'.now()->format('YmdHis').'.csv';

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

            $description = "❣️ Fundjave ne $origin Nga $destination->name ❣️";

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
