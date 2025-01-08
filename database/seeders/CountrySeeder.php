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
        $countries = [
            'France',
            'Austri',
            'Italy',
            'Spain',
            'Turqi',
            'Hungari',
            'United Emirates',
            'Austria',
            'Itali',
            'Holland',
            'Egypt',
            'Greqi',
            'Maldives',
            'Belgjike',
            'Gjermani',
            'Ceki',
            'Suedi',
            'Spanje',
            'Portugali',
            'Rumani',
            'Malta',
            'Canary',
            'Egjipt',
            'Thailand',
            'UAE',
            'Shqiperi',
            'Zvicer',
            'Angli',
            'Irelande',
            'Kosova',
            'Maqedonia Veriut',
            'Hollande',
            'Emirate',
            'Finland',
        ];

        foreach (array_unique($countries) as $country) {
            Country::updateOrCreate(['name' => $country]);
        }
    }
}
