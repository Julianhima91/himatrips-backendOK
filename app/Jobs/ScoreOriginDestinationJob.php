<?php

namespace App\Jobs;

use App\Models\Destination;
use App\Models\Origin;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ScoreOriginDestinationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $originResults = DB::table('packages')
            ->join('package_configs', 'package_configs.id', '=', 'packages.package_config_id')
            ->join('destination_origins', 'destination_origins.id', '=', 'package_configs.destination_origin_id')
            ->join('origins', 'origins.id', '=', 'destination_origins.origin_id')
            ->where('packages.created_at', '>=', now()->subDays(90))
            ->groupBy('origins.id', 'origins.name')
            ->select(
                'origins.id as origin_id',
                'origins.name as origin_name',
                DB::raw('COUNT(DISTINCT packages.batch_id) as search_count')
            )
            ->orderByDesc('search_count')
            ->get();

        foreach ($originResults as $row) {
            Origin::query()
                ->where('id', $row->origin_id)
                ->update(['search_count' => $row->search_count]);
        }

        $destinationResults = DB::table('packages')
            ->join('package_configs', 'package_configs.id', '=', 'packages.package_config_id')
            ->join('destination_origins', 'destination_origins.id', '=', 'package_configs.destination_origin_id')
            ->join('destinations', 'destinations.id', '=', 'destination_origins.destination_id')
            ->where('packages.created_at', '>=', now()->subDays(90))
            ->groupBy('destinations.id')
            ->select(
                'destinations.id as destination_id',
                DB::raw('COUNT(DISTINCT packages.batch_id) as search_count')
            )
            ->get();

        foreach ($destinationResults as $row) {
            Destination::query()
                ->where('id', $row->destination_id)
                ->update(['search_count' => $row->search_count]);
        }
    }
}
