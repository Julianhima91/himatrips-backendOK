<?php

namespace App\Console\Commands;

use DB;
use Illuminate\Console\Command;

class UpdatePackageSearchCounts extends Command
{
    protected $signature = 'packages:update-search-counts';

    protected $description = 'Update package search counts for dashboards';

    public function handle()
    {
        DB::table('package_search_counts')->truncate();

        $counts = DB::table('packages')
            ->select('package_config_id', DB::raw('COUNT(DISTINCT batch_id) as batch_count'))
            ->where('created_at', '>=', now()->subMonths(3))
            ->groupBy('package_config_id')
            ->get()
            ->map(function ($item) {
                return [
                    'package_config_id' => $item->package_config_id,
                    'batch_count' => $item->batch_count,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })
            ->toArray();

        DB::table('package_search_counts')->insert($counts);

        $this->info('Package search counts updated successfully.');
    }
}
