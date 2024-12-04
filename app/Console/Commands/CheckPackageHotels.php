<?php

namespace App\Console\Commands;

use App\Models\Package;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckPackageHotels extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:package-hotels';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Checks if packages have hotels unrelated to their destination and logs mismatches';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $now = now();
        $yesterday = $now->copy()->subDay();

        $recentSearches = Package::whereBetween('created_at', [$yesterday, $now])
            ->get();

        foreach ($recentSearches as $search) {

            $destination = $search->packageConfig->destination_origin->destination;
            $hotels = $destination->hotels;

            $searchHotelId = $search->hotelData->hotel_id;

            if (! $hotels->contains('id', $searchHotelId)) {
                Log::channel('daily')->warning('Mismatched hotels detected', [
                    'search_id' => $search->id,
                    'search_hotel_id' => $searchHotelId,
                    'destination_id' => $destination->id,
                ]);
            }
        }

        $this->info('Package hotel validation completed.');
    }
}
