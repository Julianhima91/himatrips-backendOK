<?php

namespace App\Jobs;

use App\Models\PackageConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateFlightDatesForTopSearchedPackages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $minSearches;

    protected ?int $maxSearches;

    public function __construct(int $minSearches = 4, ?int $maxSearches = null)
    {
        $this->minSearches = $minSearches;
        $this->maxSearches = $maxSearches;
    }

    public function handle(): void
    {
        $logger = Log::channel('directdates');

        $havingCondition = $this->maxSearches
            ? "HAVING COUNT(DISTINCT packages.batch_id) BETWEEN {$this->minSearches} AND {$this->maxSearches}"
            : "HAVING COUNT(DISTINCT packages.batch_id) > {$this->minSearches}";

        $sql = "
            SELECT
                packages.package_config_id,
                origins.name AS origin_name,
                destinations.name AS destination_name,
                COUNT(DISTINCT packages.batch_id) AS search_count
            FROM packages
            JOIN package_configs ON package_configs.id = packages.package_config_id
            JOIN destination_origins ON destination_origins.id = package_configs.destination_origin_id
            JOIN origins ON origins.id = destination_origins.origin_id
            JOIN destinations ON destinations.id = destination_origins.destination_id
            WHERE packages.created_at >= NOW() - INTERVAL 30 DAY
            GROUP BY
                destination_origins.origin_id,
                destination_origins.destination_id,
                packages.package_config_id,
                origins.name,
                destinations.name
            $havingCondition
            ORDER BY search_count DESC
        ";

        $topPackages = DB::select($sql);

        $logger->info('MIN SEARCHES: '.$this->minSearches);
        $logger->info('MAX SEARCHES: '.$this->maxSearches);
        $logger->info('TOTAL PACKAGE CONFIGS COUNT: '.count($topPackages));
        $logger->info('====================================');
        $logger->info('====================================');
        $logger->info('====================================');
        $logger->info('====================================');

        foreach ($topPackages as $pkg) {
            $logger->info("ID: $pkg->package_config_id | $pkg->origin_name - $pkg->destination_name | Searched: $pkg->search_count");

            $packageConfig = PackageConfig::find($pkg->package_config_id);
            if (! $packageConfig) {
                $logger->warning("PackageConfig ID {$pkg->package_config_id} not found.");

                continue;
            }

            ProcessPackageConfigJob::dispatch($packageConfig);
        }
    }
}
