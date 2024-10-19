<?php

namespace App\Console;

use App\Jobs\CheckDirectFlightForPackageConfigJob;
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
        //todo: run monthly, running it once for now to test it
        $schedule->call(function () {
            CheckDirectFlightForPackageConfigJob::dispatch();
        })->cron('* * * * *');
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
