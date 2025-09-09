<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateFlightPassengerStats extends Command
{
    protected $signature = 'flights:update-passenger-stats';

    protected $description = 'Update aggregated passenger statistics (adults + children) from flight_data';

    public function handle(): void
    {
        $this->info('Updating flight passenger stats...');

        $this->updateGlobalStats();
        // later if we want to also get the daily searches
        // we can add some option to do so.

        $this->info('Flight passenger stats updated successfully.');
    }

    protected function updateGlobalStats(): void
    {
        DB::table('flight_passenger_stats')->truncate();

        $stats = DB::table('flight_data')
            ->select('adults', 'children', DB::raw('COUNT(*) as total_flights'))
            ->groupBy('adults', 'children')
            ->get()
            ->map(fn ($row) => [
                'adults' => $row->adults,
                'children' => $row->children,
                'total_flights' => intval($row->total_flights / 2), // todo: to be removed after enough time has passed for the return column to be correct in db
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->toArray();

        DB::table('flight_passenger_stats')->insert($stats);
    }
}
