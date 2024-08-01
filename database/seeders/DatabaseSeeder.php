<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Artisan::call('make:filament-user', [
            '--name' => 'admin',
            '--email' => 'admin@dev.test',
            '--password' => 'password',
        ]);

        //        $this->call(AirlinesSeeder::class);
        //        $this->call(AirportsSeeder::class);
        //        $this->call(HotelSeeder::class);
    }
}
