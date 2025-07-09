<?php

namespace App\Jobs;

use App\Models\Hotel;
use App\Models\RoomType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractRoomTypesFromHotelOffers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        $logger = Log::channel('hotels');

        $logger->info('Starting RoomType extraction from hotel offers');

        Hotel::with(['hotelData.offers'])->chunk(100, function ($hotels) use ($logger) {
            foreach ($hotels as $hotel) {
                $existingRoomNames = RoomType::where('hotel_id', $hotel->id)->pluck('name')->toArray();

                foreach ($hotel->hotelData as $hotelData) {
                    foreach ($hotelData->offers as $offer) {
                        $roomTypes = json_decode($offer->room_type, true);

                        if (!is_array($roomTypes)) {
//                            $logger->warning("Invalid room_type JSON for Offer ID {$offer->id}");

                            continue;
                        }

                        foreach ($roomTypes as $roomName) {
                            if (!is_string($roomName)) {
                                $logger->warning("Unexpected room type format in Offer ID {$offer->id}");

                                continue;
                            }

                            if (in_array($roomName, $existingRoomNames)) {
//                                $logger->info("Skipping duplicate room type '{$roomName}' for Hotel ID {$hotel->id}");

                                continue;
                            }
                            $split = preg_split('/\s*[-–—]+\s*/u', $roomName);
                            $shortName = trim($split[0]);

                            RoomType::create([
                                'hotel_id' => $hotel->id,
                                'hotel_data_id' => $hotelData->id,
                                'hotel_offer_id' => $offer->id,
                                'name' => $shortName,
                                'details' => json_encode([
                                    'source' => 'hotel_offer',
                                    'room_full_name' => $roomName,
                                ]),
                            ]);

                            $existingRoomNames[] = $roomName;
//                            $logger->info("Saved room type '{$roomName}' for Hotel ID {$hotel->id}, Offer ID {$offer->id}");
                        }
                    }
                }
            }
        });

        $logger->info('Finished RoomType extraction');
    }
}
