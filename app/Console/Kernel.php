<?php

namespace App\Console;

use App\Console\Commands\CleanupExpiredFlights;
use App\Jobs\CheckDirectFlightForPackageConfigJob;
use App\Jobs\DestinationOriginJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        CleanupExpiredFlights::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->job(new CheckDirectFlightForPackageConfigJob)->monthlyOn(1, '00:00');
        $schedule->job(new DestinationOriginJob)->dailyAt('00:00');
        $schedule->command('ads:refresh economic')->everyFifteenMinutes();
        $schedule->command('ads:refresh weekend')->everyFifteenMinutes();
        $schedule->command('ads:refresh holiday')->everyFifteenMinutes();
        $schedule->command('check:package-hotels')->dailyAt('01:00');
        $schedule->command('check:package-flights')->dailyAt('01:00');
        $schedule->command('flights:truncate')->monthlyOn(1, '00:00');
        
        // Clean up old search data (packages, flights, hotels) older than 10 days
        $schedule->command('search-data:cleanup --days=10')
                 ->dailyAt('02:00')
                 ->withoutOverlapping()
                 ->runInBackground();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
