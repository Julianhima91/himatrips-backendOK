<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;

class CountrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Mapping of country names to ISO 2-letter country codes
        $countries = [
            'France' => 'FR',
            'Austri' => 'AT',
            'Italy' => 'IT',
            'Spain' => 'ES',
            'Turqi' => 'TR',
            'Hungari' => 'HU',
            'United Emirates' => 'AE',
            'Austria' => 'AT',
            'Itali' => 'IT',
            'Holland' => 'NL',
            'Egypt' => 'EG',
            'Greqi' => 'GR',
            'Maldives' => 'MV',
            'Belgjike' => 'BE',
            'Gjermani' => 'DE',
            'Ceki' => 'CZ',
            'Suedi' => 'SE',
            'Spanje' => 'ES',
            'Portugali' => 'PT',
            'Rumani' => 'RO',
            'Malta' => 'MT',
            'Canary' => 'ES', // Canary Islands are part of Spain
            'Egjipt' => 'EG',
            'Thailand' => 'TH',
            'UAE' => 'AE',
            'Shqiperi' => 'AL',
            'Zvicer' => 'CH',
            'Angli' => 'GB',
            'Irelande' => 'IE',
            'Kosova' => 'XK', // Kosovo uses XK as ISO code (not official but widely used)
            'Maqedonia Veriut' => 'MK',
            'Hollande' => 'NL',
            'Emirate' => 'AE',
            'Finland' => 'FI',
        ];

        foreach ($countries as $name => $code) {
            Country::updateOrCreate(
                ['name' => $name],
                ['code' => $code]
            );
        }
    }
}
