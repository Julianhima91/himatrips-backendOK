<?php

namespace App\Console\Commands;

use App\Models\DirectFlightAvailability;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TruncateDirectFlights extends Command
{
    protected $signature = 'flights:truncate';

    protected $description = 'Truncate all direct flights';

    public function handle()
    {
        $this->info('Truncating direct flights...');
        DirectFlightAvailability::truncate();
        $this->info('All direct flights have been truncated.');

        $this->info('Resetting last processed month in package configs...');
        DB::table('package_configs')->update(['last_processed_month' => null]);
        $this->info('Last processed month has been reset.');
    }
}
