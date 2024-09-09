<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupExpiredFlights extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'flights:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove rows from direct_flights_availabilities where the date has already passed.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = now();

        DB::table('direct_flight_availabilities')
            ->where('date', '<', $now)
            ->delete();

        //todo: Flight_data and hotel_data prune

        $this->info('Expired flights from direct_flights_availability have been cleaned up.');
    }
}
