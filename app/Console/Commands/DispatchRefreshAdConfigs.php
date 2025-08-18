<?php

namespace App\Console\Commands;

use App\Models\AdConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as CommandAlias;

class DispatchRefreshAdConfigs extends Command
{
    protected $signature = 'ads:refresh
        {type : economic|weekend|holiday}
        {--queue= : Queue name to dispatch jobs to}';

    protected $description = 'Dispatch jobs for ad_configs needing refresh by type';

    public function handle()
    {
        $now = now();
        $type = $this->argument('type');

        $validTypes = ['economic', 'weekend', 'holiday'];
        if (! in_array($type, $validTypes)) {
            $this->error('Invalid type. Must be one of: '.implode(', ', $validTypes));

            return CommandAlias::INVALID;
        }

        // Default queue name = type
        $queue = $this->option('queue') ?: $type;

        $jobClasses = [
            'economic' => \App\Jobs\EconomicAdJob::class,
            'weekend' => \App\Jobs\WeekendAdJob::class,
            'holiday' => \App\Jobs\HolidayAdJob::class,
        ];

        $statusColumn = "{$type}_status";
        $lastRunColumn = "{$type}_last_run";

        AdConfig::where(function ($query) use ($now, $lastRunColumn) {
            $query->whereNull($lastRunColumn)
                ->orWhereRaw("TIMESTAMPDIFF(HOUR, {$lastRunColumn}, ?) >= refresh_hours", [$now]);
        })
            ->where($statusColumn, '!=', 'running')
            ->orderByRaw("COALESCE({$lastRunColumn}, created_at) ASC")
            ->chunkById(5, function ($configs) use ($queue, $statusColumn, $lastRunColumn, $type, $jobClasses) {
                foreach ($configs as $adConfig) {
                    $jobClass = $jobClasses[$type] ?? null;

                    if (! $jobClass) {
                        $this->error("No job class found for type: {$type}");

                        return CommandAlias::INVALID;
                    }

                    $jobClass::dispatch(adConfigId: $adConfig->id)->onQueue($queue);

                    Log::info($adConfig->id);
                    Log::info($type);
                    Log::info($jobClass);

                    $adConfig->update([
                        $statusColumn => 'running',
                        $lastRunColumn => now(),
                    ]);
                }
            });

        $this->info(ucfirst($type)." ad config jobs dispatched to [{$queue}] queue.");

        return CommandAlias::SUCCESS;
    }
}
