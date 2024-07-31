<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HotelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        ini_set('memory_limit', '512M');

        $csvFile = database_path('seeders/csv/hotels.csv');
        $handle = fopen($csvFile, 'r');

        fgetcsv($handle);

        $batchSize = 500;
        $rows = [];

        while (($data = fgetcsv($handle, 1000, '|')) !== false) {
            $rows[] = [
                'country_id' => $data[0],
                'country' => $data[1],
                'iso_code' => $data[2],
                'city_id' => $data[3],
                'city' => $data[4],
                'hotel_id' => $data[5],
                'name' => $data[6],
                'address' => $data[7],
                'phone' => $data[8],
                'fax' => $data[9],
                'stars' => $data[10],
                'stars_id' => $data[11],
                'longitude' => $data[12],
                'latitude' => $data[13],
                'is_apartment' => $data[14] == 'False' ? 0 : 1,
                'giata_code' => $data[15],
                // 'destination_id' => ... // You can set this if needed
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // If the rows array has reached the batch size, insert and reset the rows array
            if (count($rows) === $batchSize) {
                DB::table('hotels')->insert($rows);
                $rows = [];
            }
        }

        // Insert any remaining rows
        if (count($rows) > 0) {
            DB::table('hotels')->insert($rows);
        }

        fclose($handle);
    }
}
