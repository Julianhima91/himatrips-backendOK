<?php

namespace App\Console\Commands;

use App\Jobs\EconomicAdJob;
use App\Models\AdConfig;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;

class DispatchRefreshAdConfigs extends Command
{
    protected $signature = 'ads:refresh';

    protected $description = 'Dispatch jobs for ad_configs needing refresh';

    public function handle()
    {
        $now = now();

        AdConfig::where(function ($query) use ($now) {
            $query->whereNull('job_updated_at')
                ->orWhereRaw('TIMESTAMPDIFF(HOUR, job_updated_at, ?) >= refresh_hours', [$now]);
        })
            ->where('job_status', '!=', 'running')
            ->orderByRaw('COALESCE(job_updated_at, created_at) ASC')
            ->chunk(5, function ($configs) {
                foreach ($configs as $adConfig) {
                    $adConfig->update(['job_status' => 'running']);
                    EconomicAdJob::dispatch(adConfigId: $adConfig->id);
                }
            });

        $this->info('Refreshed all ad config jobs successfully.');

        return CommandAlias::SUCCESS;
    }
}
