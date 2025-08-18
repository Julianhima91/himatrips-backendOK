<?php

namespace App\Jobs;

use App\Enums\BoardOptionEnum;
use App\Models\Ad;
use App\Models\AdConfig;
use App\Models\AdConfigCsv;
use App\Models\DestinationOrigin;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class WeekendAppendDestinationToCSVJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private AdConfig $adConfig;

    private $batchIds;

    private $destinationId;

    public function __construct($adConfig, $batchIds, $destinationId)
    {
        $this->adConfig = $adConfig;
        $this->batchIds = $batchIds;
        $this->destinationId = $destinationId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $logger = Log::channel('weekend');

        $logger->warning('APPENDING ...');

        $cheapestAds = Ad::select('destination_id', DB::raw('MIN(total_price) as min_price'))
            ->where([
                ['ad_config_id', $this->adConfig->id],
                ['offer_category', 'weekend'],
                ['destination_id', $this->destinationId],
            ])
            ->whereIn('batch_id', $this->batchIds)
            ->groupBy('destination_id')
            ->get();

        foreach ($cheapestAds as $cheapestAd) {
            $ad = Ad::where([
                ['ad_config_id', $this->adConfig->id],
                ['offer_category', 'weekend'],
                ['destination_id', $cheapestAd->destination_id],
                ['total_price', $cheapestAd->min_price],
            ])
                ->whereIn('batch_id', $this->batchIds)
                ->first();

            $logger->warning("Ad ID: $ad->id");

            Ad::where([
                ['ad_config_id', $this->adConfig->id],
                ['offer_category', 'weekend'],
                ['destination_id', $cheapestAd->destination_id],
            ])
                ->whereIn('batch_id', $this->batchIds)
                ->where('id', '!=', $ad->id)
                ->delete();
        }

        $ads = Ad::query()
            ->whereIn('batch_id', $this->batchIds)
            ->orderBy('total_price', 'asc')
            ->get();

        $csv = AdConfigCsv::query()
            ->where('ad_config_id', $this->adConfig->id)
            ->where('file_path', 'like', '%weekend%')
            ->first();

        if (count($ads) == 0) {
            $logger->warning('==========================');
            $logger->warning('No Ads available to append');
            $logger->warning('==========================');
        }

        $this->appendToCSV($ads, $csv);
    }

    public function appendToCSV($ads, $csv): void
    {
        $logger = Log::channel('weekend');

        $totalAds = count($ads);
        $adConfig = $ads[0]->ad_config_id;
        $logger->info("Appending $totalAds ads for weekend... Ad config id: $adConfig");

        $directory = storage_path('app/public/offers');
        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $filepath = $directory.'/'.$csv->file_path;

        $existingHeaders = null;
        $fileForRead = fopen($filepath, 'r');
        $existingHeaders = fgetcsv($fileForRead);
        fclose($fileForRead);

        $file = fopen($filepath, 'a');

        $maxImages = 0;
        $maxTagsPerImage = [];
        $maxVideos = 0;
        $maxTagsPerVideo = [];
        $maxDestinationTags = 0;

        foreach ($existingHeaders as $header) {
            // Match image[n].url
            if (preg_match('/^image\[(\d+)\]\.url$/', $header, $matches)) {
                $index = (int) $matches[1];
                $maxImages = max($maxImages, $index + 1);
                $maxTagsPerImage[$index] = $maxTagsPerImage[$index] ?? 0;
            }

            // Match image[n].tag[m]
            elseif (preg_match('/^image\[(\d+)\]\.tag\[(\d+)\]$/', $header, $matches)) {
                $imgIndex = (int) $matches[1];
                $tagIndex = (int) $matches[2];
                $maxImages = max($maxImages, $imgIndex + 1);
                $maxTagsPerImage[$imgIndex] = max($maxTagsPerImage[$imgIndex] ?? 0, $tagIndex + 1);
            }

            // Match video[n].url
            elseif (preg_match('/^video\[(\d+)\]\.url$/', $header, $matches)) {
                $index = (int) $matches[1];
                $maxVideos = max($maxVideos, $index + 1);
                $maxTagsPerVideo[$index] = $maxTagsPerVideo[$index] ?? 0;
            }

            // Match video[n].tag[m]
            elseif (preg_match('/^video\[(\d+)\]\.tag\[(\d+)\]$/', $header, $matches)) {
                $vidIndex = (int) $matches[1];
                $tagIndex = (int) $matches[2];
                $maxVideos = max($maxVideos, $vidIndex + 1);
                $maxTagsPerVideo[$vidIndex] = max($maxTagsPerVideo[$vidIndex] ?? 0, $tagIndex + 1);
            }

            // Match type[n]
            elseif (preg_match('/^type\[(\d+)\]$/', $header, $matches)) {
                $tagIndex = (int) $matches[1];
                $maxDestinationTags = max($maxDestinationTags, $tagIndex + 1);
            }
        }

        $logger->error($maxDestinationTags);
        $logger->error($maxImages);
        $logger->error($maxTagsPerImage);
        $logger->error($maxVideos);
        $logger->error($maxTagsPerVideo);

        foreach ($ads as $ad) {
            $destinationOrigin = DestinationOrigin::where([
                ['destination_id', $ad->destination_id],
                ['origin_id', $ad->adConfig->origin_id],
            ])->first();

            $photos = $destinationOrigin->photos
                ->filter(fn ($file) => ! str_ends_with($file->file_path, '.mp4'))
                ->values();

            $videos = $destinationOrigin->photos
                ->filter(fn ($file) => str_ends_with($file->file_path, '.mp4'))
                ->values();

            $destinationTags = $ad->destination->tags;

            //            $maxImages = max($maxImages, $photos->count());
            //            $maxVideos = max($maxVideos, $videos->count());
            //            $maxDestinationTags = min(3, max($maxDestinationTags, $destinationTags->count()));

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

            $enum = BoardOptionEnum::fromName($boardOptions);

            if ($enum) {
                $labelMap = [
                    BoardOptionEnum::BB->name => '✅ Me Mëngjes',
                    BoardOptionEnum::HB->name => '✅ Half Board',
                    BoardOptionEnum::FB->name => '✅ Full Board',
                    BoardOptionEnum::AI->name => '✅ All Inclusive',
                    BoardOptionEnum::RO->name => '✅ Vetëm Dhoma',
                    BoardOptionEnum::CB->name => '✅ Mëngjes Kontinental',
                    BoardOptionEnum::BD->name => '✅ Mëngjes & Darkë',
                ];

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

            $destinationOrigin = DestinationOrigin::where([
                ['destination_id', $ad->destination_id],
                ['origin_id', $ad->adConfig->origin_id],
            ])->first();

            $photos = $destinationOrigin->photos->filter(fn ($file) => ! str_ends_with($file->file_path, '.mp4'))->values();
            $videos = $destinationOrigin->photos->filter(fn ($file) => str_ends_with($file->file_path, '.mp4'))->values();

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
    }
}
