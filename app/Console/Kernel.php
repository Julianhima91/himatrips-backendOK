<?php

namespace App\Console;

use App\Console\Commands\DispatchRefreshAdConfigs;
use App\Jobs\CheckDirectFlightForPackageConfigJob;
use App\Jobs\DestinationOriginJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        Commands\CleanupExpiredFlights::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->job(new CheckDirectFlightForPackageConfigJob)->dailyAt('00:00');
        $schedule->job(new DestinationOriginJob)->dailyAt('00:00');
        $schedule->job(new DispatchRefreshAdConfigs)->everyFifteenMinutes();
        $schedule->command('check:package-hotels')->dailyAt('01:00');
        $schedule->command('check:package-flights')->dailyAt('01:00');
        $schedule->command('flights:truncate')->monthlyOn(1, '00:00');
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
