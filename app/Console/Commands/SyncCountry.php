<?php

namespace App\Console\Commands;

use App\Models\Country;
use App\Models\Destination;
use App\Models\Origin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as CommandAlias;

class SyncCountry extends Command
{
    protected $signature = 'sync:country-ids';

    protected $description = 'Sync country_id for origins and destinations based on country column';

    public function handle()
    {
        $countries = Country::all()->pluck('id', 'name');

        $origins = Origin::all();
        foreach ($origins as $origin) {
            if (isset($countries[$origin->country])) {
                DB::table('origins')->where('id', $origin->id)->update([
                    'country_id' => $countries[$origin->country],
                ]);
            }
        }

        $destinations = Destination::all();
        foreach ($destinations as $destination) {
            if (isset($countries[$destination->country])) {
                DB::table('destinations')->where('id', $destination->id)->update([
                    'country_id' => $countries[$destination->country],
                ]);
            }
        }
        $this->info('Country IDs synced successfully!');

        return CommandAlias::SUCCESS;
    }
}
