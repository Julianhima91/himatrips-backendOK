<?php

namespace App\Listeners;

use App\Enums\BoardOptionEnum;
use App\Models\Ad;
use App\Models\AdConfig;
use App\Models\AdConfigCsv;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class CheckEconomicJobCompletedListener
{
    public function __construct() {}

    public function handle(object $event): void
    {
        $logger = Log::channel('economic');

        $logger->info('==================ECONOMIC LISTENER==================');

        $batchIds = Cache::get("$event->adConfigId:economic_create_csv");
        $currentCsvBatchIds = Cache::get("$event->adConfigId:current_economic_csv_batch_ids");

        if ($event->batchId) {
            $currentCsvBatchIds[] = (string) $event->batchId;
            Cache::put("$event->adConfigId:current_economic_csv_batch_ids", $currentCsvBatchIds, now()->addMinutes(180));
        }

        $adConfig = AdConfig::find($event->adConfigId);
        $destinationsWithActiveMonths = $adConfig->destinations->whereNotNull('active_months');
        $numberOfAirports = $adConfig->airports->count();
        $monthsCount = 0;

        foreach ($destinationsWithActiveMonths as $destination) {
            $extraMonths = count($destination->active_months);
            $monthsCount += $extraMonths;
        }

        $total = $numberOfAirports * $monthsCount;

        if (isset($currentCsvBatchIds) && isset($batchIds)) {
            $batchIds = array_unique($batchIds);

            if (count($batchIds) === $total && count($currentCsvBatchIds) === $total) {
                sort($batchIds);
                sort($currentCsvBatchIds);
            }
        }

        $logger->info($batchIds);
        $logger->info($currentCsvBatchIds);
        //        $logger->info($batchIds === $currentCsvBatchIds);
        //        $logger->info("TOTAL: $total");

        if (count(array_diff($batchIds, $currentCsvBatchIds)) === 0 && count(array_diff($currentCsvBatchIds, $batchIds)) === 0) {
            //            && count($batchIds) === $total && count($currentCsvBatchIds) === $total)
            $logger->info('SUCCESS (ECONOMIC)');

            Cache::forget("$event->adConfigId:economic_create_csv");
            Cache::forget("$event->adConfigId:current_economic_csv_batch_ids");

            //todo: change for 1 ad per destination
            //            foreach ($batchIds as $batchId) {
            //                $cheapestAd = Ad::where('batch_id', $batchId)
            //                    ->orderBy('total_price', 'asc')
            //                    ->first();
            //
            //                Ad::where('batch_id', $batchId)
            //                    ->where('id', '!=', optional($cheapestAd)->id)
            //                    ->delete();
            //            }
            $cheapestAds = Ad::select('destination_id', DB::raw('MIN(total_price) as min_price'))
                ->where([
                    ['ad_config_id', $event->adConfigId],
                    ['offer_category', 'economic'],
                ])
                ->whereIn('batch_id', $batchIds)
                ->groupBy('destination_id')
                ->get();

            $logger->warning('Count'.count($cheapestAds));

            foreach ($cheapestAds as $cheapestAd) {
                $ad = Ad::where([
                    ['ad_config_id', $event->adConfigId],
                    ['offer_category', 'economic'],
                    ['destination_id', $cheapestAd->destination_id],
                    ['total_price', $cheapestAd->min_price],
                ])
                    ->whereIn('batch_id', $batchIds)
                    ->first();

                $logger->warning("Ad ID: $ad->id");

                Ad::where([
                    ['ad_config_id', $event->adConfigId],
                    ['offer_category', 'economic'],
                    ['destination_id', $cheapestAd->destination_id],
                ])
                    ->whereIn('batch_id', $batchIds)
                    ->where('id', '!=', $ad->id)
                    ->delete();
            }

            $ads = Ad::query()
                ->whereIn('batch_id', $batchIds)
                ->orderBy('total_price', 'asc')
                ->get();

            [$csvPath, $adConfigId] = $this->exportAdsToCsv($ads);

            AdConfigCsv::updateOrCreate([
                'ad_config_id' => $adConfigId,
                'file_path' => $csvPath,
            ]);
        }
    }

    public function exportAdsToCsv($ads)
    {
        $logger = Log::channel('economic');

        $totalAds = count($ads);
        $adConfig = $ads[0]->ad_config_id;
        $adConfigDescription = preg_replace('/\s+/', '_', $ads[0]->adConfig->description ?? 'no_description');
        $logger->info("Exporting $totalAds ads for economic... Ad config id: $adConfig");

        $filename = 'ads_economic_export_'.$adConfigDescription.'.csv';

        $directory = storage_path('app/public/offers');
        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $filepath = $directory.'/'.$filename;

        $file = fopen($filepath, 'w');

        $maxImages = 0;
        $maxTagsPerImage = [];

        $maxVideos = 0;
        $maxTagsPerVideo = [];
        $maxDestinationTags = 0;

        foreach ($ads as $ad) {
            $photos = $ad->destination->destinationPhotos
                ->filter(fn ($file) => ! str_ends_with($file->file_path, '.mp4'))
                ->values();

            $videos = $ad->destination->destinationPhotos
                ->filter(fn ($file) => str_ends_with($file->file_path, '.mp4'))
                ->values();

            $destinationTags = $ad->destination->tags;

            $maxImages = max($maxImages, $photos->count());
            $maxVideos = max($maxVideos, $videos->count());
            $maxDestinationTags = min(3, max($maxDestinationTags, $destinationTags->count()));

            foreach ($photos as $index => $photo) {
                $tagsCount = count($photo->tags);
                $maxTagsPerImage[$index] = max($maxTagsPerImage[$index] ?? 0, $tagsCount);
            }

            foreach ($videos as $index => $video) {
                $tagsCount = count($video->tags);
                $maxTagsPerVideo[$index] = max($maxTagsPerVideo[$index] ?? 0, $tagsCount);
            }
        }

        // 1st part
        $headers = [
            //we can remove id, only for debugging
            //            'id',
            'destination_id',
            'price',
            'name',
            'description',
            'custom_label_0',
        ];

        // dynamic fields
        for ($i = 0; $i < $maxImages; $i++) {
            $headers[] = "image[$i].url";

            for ($j = 0; $j < ($maxTagsPerImage[$i] ?? 0); $j++) {
                $headers[] = "image[$i].tag[$j]";
            }
        }

        for ($i = 0; $i < $maxVideos; $i++) {
            $headers[] = "video[$i].url";

            for ($j = 0; $j < ($maxTagsPerVideo[$i] ?? 0); $j++) {
                $headers[] = "video[$i].tag[$j]";
            }
        }

        for ($i = 0; $i < $maxDestinationTags; $i++) {
            $headers[] = "type[$i]";
        }

        // end part
        $headers = array_merge($headers, [
            'address.addr1',
            'address.city',
            'address.region',
            'address.country',
            'latitude',
            'longitude',
            'neighborhood[0]',
            'product_tags[0]',
            'price_change',
            'url',
        ]);

        fputcsv($file, $headers);

        $months = [
            'January' => 'Janar', 'February' => 'Shkurt', 'March' => 'Mars',
            'April' => 'Prill', 'May' => 'Maj', 'June' => 'Qershor',
            'July' => 'Korrik', 'August' => 'Gusht', 'September' => 'Shtator',
            'October' => 'Tetor', 'November' => 'Nëntor', 'December' => 'Dhjetor',
        ];

        foreach ($ads as $ad) {
            $formatDate = fn ($date) => $date->format('d').' '.$months[$date->format('F')];
            $boardOptions = $ad->hotelData->cheapestOffer->first()->room_basis;

            $origin = $ad->adConfig->origin->name;
            $destination = $ad->destination;

            $temp = '';
            $logger->error($boardOptions);

            $enum = BoardOptionEnum::fromName($boardOptions);
            $logger->error($enum->name); // logs 'RO', 'BB', etc.

            if ($enum) {
                $logger->error('ENUM EXISTS');
                $logger->error(BoardOptionEnum::BB->name);
                $labelMap = [
                    BoardOptionEnum::BB->name => '✅ Me Mëngjes',
                    BoardOptionEnum::HB->name => '✅ Half Board',
                    BoardOptionEnum::FB->name => '✅ Full Board',
                    BoardOptionEnum::AI->name => '✅ All Inclusive',
                    BoardOptionEnum::RO->name => '✅ Vetëm Dhoma',
                    BoardOptionEnum::CB->name => '✅ Mëngjes Kontinental',
                    BoardOptionEnum::BD->name => '✅ Mëngjes & Darkë',
                ];
                $logger->error($labelMap[$enum->name]);

                $temp = $labelMap[$enum->name] ?? '';
            }

            $message = "❣️ Oferta Ekonomike ne $destination->name Nga $origin ❣️
✈️ ".$formatDate($ad->outboundFlight->departure).' - '.$formatDate($ad->inboundFlight->departure).' ➥ '.(floor($ad->total_price / 2)).' €/P '.$ad->hotelData->number_of_nights.' Nete
✅ Bilete Vajtje - Ardhje nga '.$ad->adConfig->origin->name.'
✅ Cante 10 Kg
✅ Taksa Aeroportuale
✅ Akomodim ne Hotel
'.$temp.'
📍 Tiranë: Tek kryqëzimi i Rrugës Muhamet Gjollesha me Myslym Shyrin.
📞 +355694767427';

            $customLabel = '🌍️ Pushimet e tua me nisje nga '.$ad->adConfig->origin->name.'! - Zgjidh midis ofertave me te mira sot!
💡 Rezervo tani!📞 Ofertat janë të limituara!';

            $row = [
                //we can remove id, only for debugging
                //                $ad->id,
                $ad->package_config_id,
                floor($ad->total_price / 2),
                "❣️ Oferta Ekonomike ne $destination->name Nga $origin ❣️",
                $message,
                $customLabel,
            ];

            $photos = $ad->destination->destinationPhotos->filter(fn ($file) => ! str_ends_with($file->file_path, '.mp4'))->values();
            $videos = $ad->destination->destinationPhotos->filter(fn ($file) => str_ends_with($file->file_path, '.mp4'))->values();

            for ($i = 0; $i < $maxImages; $i++) {
                if (isset($photos[$i])) {
                    $row[] = url('/storage/'.$photos[$i]->file_path);

                    $tags = $photos[$i]->tags->pluck('name')->toArray();
                    for ($j = 0; $j < ($maxTagsPerImage[$i] ?? 0); $j++) {
                        $row[] = $tags[$j] ?? '';
                    }
                } else {
                    $row[] = '';
                    for ($j = 0; $j < ($maxTagsPerImage[$i] ?? 0); $j++) {
                        $row[] = '';
                    }
                }
            }

            for ($i = 0; $i < $maxVideos; $i++) {
                if (isset($videos[$i])) {
                    $row[] = url('/storage/'.$videos[$i]->file_path);

                    $tags = $videos[$i]->tags->pluck('name')->toArray();
                    for ($j = 0; $j < ($maxTagsPerVideo[$i] ?? 0); $j++) {
                        $row[] = $tags[$j] ?? '';
                    }
                } else {
                    $row[] = '';
                    for ($j = 0; $j < ($maxTagsPerVideo[$i] ?? 0); $j++) {
                        $row[] = '';
                    }
                }
            }

            $currentAdDestinationTags = $ad->destination->tags;

            for ($i = 0; $i < $maxDestinationTags; $i++) {
                $row[] = $currentAdDestinationTags[$i]->name ?? '';
            }

            $cheapestPrice = $ad->hotelData->cheapestOffer[0]->price ?? 0;
            $mostExpensivePrice = $ad->hotelData->mostExpensiveOffer[0]->price ?? 0;

            if ($mostExpensivePrice != 0) {
                $discountPercentage = round((($mostExpensivePrice - $cheapestPrice) / $mostExpensivePrice) * 100);

                $logger->info('Cheapest Hotel Offer Price: '.$cheapestPrice);
                $logger->info('Most Expensive Hotel Offer Price: '.$mostExpensivePrice);
                $logger->info('Discount Percentage: '.$discountPercentage.'%');
            } else {
                $discountPercentage = 0;

                $logger->warning('No valid price found for most expensive offer.');
            }

            $row = array_merge($row, [
                $ad->destination->address,
                $ad->destination->city,
                $ad->destination->region,
                $ad->destination->country,
                $ad->destination->latitude,
                $ad->destination->longitude,
                $ad->destination->neighborhood,
                $ad->offer_category,
                '-'.$discountPercentage,
                config('app.front_url')."/offers/$ad->id",
            ]);

            fputcsv($file, $row);
        }

        fclose($file);

        //
        //        fputcsv($file, [
        //            'ID',
        //            'Destination ID',
        //            'Batch ID',
        //            'Total Price',
        //            'Title',
        //            'Description',
        //            'Photos',
        //            'Videos',
        //            'Destination Tags',
        //            'Address',
        //            'City',
        //            'Country',
        //            'Latitude',
        //            'Longitude',
        //            'Neighborhood',
        //            'Product Tag',
        //            'Price Change',
        //            'URL',
        //        ]);
        //
        //        foreach ($ads as $ad) {
        //            $logger->warning($ad->id);
        //            $nights = $ad->hotelData->number_of_nights;
        //            $pricePerPerson = $ad->total_price / 2;
        //            $departureDate = $ad->outboundFlight->departure->format('d/m');
        //            $arrivalDate = $ad->inboundFlight->departure->format('d/m');
        //            $origin = $ad->adConfig->origin->name;
        //            $destination = $ad->destination;
        //
        //            $description = "❣️ Oferta Ekonomike ne $origin Nga $destination->name ❣️";
        //
        //            $body = "
        //❣️ Oferta Ekonomike ne $origin Nga $destination->name ❣️
        //✈️ $departureDate - $arrivalDate ➥ $pricePerPerson €/P $nights Nete
        //Te Perfshira :
        //✅ Bilete Vajtje - Ardhje nga $origin
        //✅ Cante 10 Kg
        //✅ Taksa Aeroportuale
        //✅ Akomodim ne Hotel
        //✅ Me Mengjes
        //------- ⭐ Whatsaap ose Instagram Per Info ⭐-------
        //📫 Zyrat Tona
        //📍 Tiranë , Tek kryqëzimi i Rrugës Muhamet Gjollesha me Myslym Shyrin.
        //📞 +355694767427
        //📍 Durres : Rruga Aleksander Goga , Perballe shkolles Eftali Koci
        //📞 +355699868907";
        //
        //            $photos = $destination->destinationPhotos->filter(function ($file) {
        //                return ! str_ends_with($file->file_path, '.mp4');
        //            })->map(function ($photo) {
        //                return [
        //                    'url' => url('/storage/'.$photo->file_path),
        //                    'tags' => implode(', ', $photo->tags->pluck('name')->toArray()),
        //                ];
        //            });
        //
        //            $photoData = $photos->map(function ($photo) {
        //                return $photo['url'].' '.$photo['tags'];
        //            })->implode(', ');
        //
        //            $videos = $destination->destinationPhotos->filter(function ($file) {
        //                return str_ends_with($file->file_path, '.mp4'); // Only videos
        //            })->map(function ($video) {
        //                return [
        //                    'url' => url('/storage/'.$video->file_path),
        //                    'tags' => implode(', ', $video->tags->pluck('name')->toArray()),
        //                ];
        //            });
        //
        //            $videoData = $videos->map(function ($video) {
        //                return $video['url'].' '.$video['tags'];
        //            })->implode(', ');
        //
        //            $destinationTags = implode(', ', $destination->tags->pluck('name')->toArray());
        //
        //            $mostExpensiveOffer = $ad->hotelData->mostExpensiveOffer;
        //            $cheapestOffer = $ad->hotelData->cheapestOffer;
        //
        //            $priceDiff = $cheapestOffer[0]->price - $mostExpensiveOffer[0]->price;
        //
        //            $requestData = json_decode($ad->request_data, true);
        //
        //            $originName = strtolower($origin);
        //            $destinationName = strtolower($destination->name);
        //            $url = env('FRONT_URL')."/admin/$ad->id";
        //
        //            fputcsv($file, [
        //                $ad->id,
        //                $destination->id,
        //                $ad->batch_id,
        //                $ad->total_price,
        //                $description,
        //                $body,
        //                $photoData,
        //                $videoData,
        //                $destinationTags,
        //                $destination->address,
        //                $destination->city,
        //                $destination->country,
        //                $destination->latitude,
        //                $destination->longitude,
        //                $destination->neighborhood,
        //                $ad->offer_category,
        //                $priceDiff,
        //                $url,
        //            ]);
        //        }
        //
        //        fclose($file);

        return [$filename, $adConfig];
    }
}
