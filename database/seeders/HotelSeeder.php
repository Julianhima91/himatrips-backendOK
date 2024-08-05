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

        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            $rows[] = [
                'id' => $data[0],
                'country_id' => $data[16],
                'country' => $data[15],
                'iso_code' => $data[14],
                'city_id' => $data[12],
                'city' => $data[13],
                'hotel_id' => $data[1],
                'name' => $data[2],
                'address' => $data[3],
                'phone' => $data[4],
                'fax' => $data[5],
                'stars' => $data[6],
                'stars_id' => $data[7],
                'longitude' => $data[8],
                'latitude' => $data[9],
                'is_apartment' => $data[10] == 'False' ? 0 : 1,
                'giata_code' => $data[11],
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
