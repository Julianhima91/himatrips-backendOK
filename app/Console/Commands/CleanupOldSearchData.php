<?php

namespace App\Console\Commands;

use App\Models\Package;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanupOldSearchData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'search-data:cleanup 
                            {--days=10 : Number of days to keep} 
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--chunk-size=5000 : Number of records to delete per batch (default: 5000)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old search data (packages, flights, hotels) older than specified days (default: 10 days)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk-size');
        $cutoffDate = now()->subDays($days);

        $this->info("Starting cleanup of data older than {$days} days (before {$cutoffDate->format('Y-m-d H:i:s')})...");
        $this->info("Using chunk size: {$chunkSize} records per batch");
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No data will be deleted');
        }

        $startTime = microtime(true);

        try {
            // Step 1: Find and delete old packages (with soft deletes, need forceDelete)
            $this->info('Step 1: Cleaning up old packages...');
            $packagesDeleted = $this->cleanupPackages($cutoffDate, $dryRun, $chunkSize);

            // Step 2: Delete orphaned flight_data (not used by any packages)
            $this->info('Step 2: Cleaning up orphaned flight data...');
            $flightsDeleted = $this->cleanupOrphanedFlights($cutoffDate, $dryRun, $chunkSize);

            // Step 3: Delete orphaned hotel_data (not used by any packages)
            // hotel_offers will be deleted automatically due to cascade
            $this->info('Step 3: Cleaning up orphaned hotel data...');
            $hotelsDeleted = $this->cleanupOrphanedHotels($cutoffDate, $dryRun, $chunkSize);

            // Step 4: Clean up client_searches (has soft deletes)
            $this->info('Step 4: Cleaning up old client searches...');
            $clientSearchesDeleted = $this->cleanupClientSearches($cutoffDate, $dryRun, $chunkSize);

            $endTime = microtime(true);
            $executionTime = round($endTime - $startTime, 2);

            $this->newLine();
            $this->info('=== Cleanup Summary ===');
            $this->info("Packages deleted: {$packagesDeleted}");
            $this->info("Flight data deleted: {$flightsDeleted}");
            $this->info("Hotel data deleted: {$hotelsDeleted}");
            $this->info("Client searches deleted: {$clientSearchesDeleted}");
            $this->info("Total execution time: {$executionTime} seconds");

            if ($dryRun) {
                $this->warn('DRY RUN completed - No data was actually deleted');
            } else {
                $this->info('Cleanup completed successfully!');
                
                // Log the cleanup
                Log::info('Search data cleanup completed', [
                    'days' => $days,
                    'cutoff_date' => $cutoffDate->format('Y-m-d H:i:s'),
                    'packages_deleted' => $packagesDeleted,
                    'flights_deleted' => $flightsDeleted,
                    'hotels_deleted' => $hotelsDeleted,
                    'client_searches_deleted' => $clientSearchesDeleted,
                    'execution_time' => $executionTime,
                ]);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error during cleanup: ' . $e->getMessage());
            Log::error('Search data cleanup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Clean up old packages (with soft deletes)
     * Optimized for large datasets (4+ million rows)
     */
    private function cleanupPackages($cutoffDate, $dryRun, $chunkSize = 5000): int
    {
        // Use raw query for better performance with large datasets
        $countQuery = DB::table('packages')
            ->where('created_at', '<', $cutoffDate);

        $count = $countQuery->count();

        if ($count === 0) {
            $this->line("  No old packages found.");
            return 0;
        }

        $this->line("  Found {$count} old packages to delete.");

        if ($dryRun) {
            return $count;
        }

        // For large datasets, use direct DELETE with chunking for better performance
        // This bypasses Eloquent overhead and soft deletes
        $deleted = 0;
        $totalChunks = ceil($count / $chunkSize);
        $currentChunk = 0;

        $this->line("  Processing in chunks of {$chunkSize}...");

        // Delete in chunks using raw SQL for maximum performance
        while (true) {
            $ids = DB::table('packages')
                ->where('created_at', '<', $cutoffDate)
                ->limit($chunkSize)
                ->pluck('id')
                ->toArray();

            if (empty($ids)) {
                break;
            }

            // Delete from packages table directly (bypasses soft deletes)
            $chunkDeleted = DB::table('packages')
                ->whereIn('id', $ids)
                ->delete();

            $deleted += $chunkDeleted;
            $currentChunk++;

            // Show progress every 10 chunks
            if ($currentChunk % 10 === 0 || $currentChunk === $totalChunks) {
                $progress = round(($currentChunk / $totalChunks) * 100, 1);
                $this->line("  Progress: {$currentChunk}/{$totalChunks} chunks ({$progress}%) - {$deleted} deleted so far...");
            }
        }

        $this->line("  Deleted {$deleted} packages.");

        return $deleted;
    }

    /**
     * Clean up orphaned flight_data (not used by any packages)
     * Optimized query using LEFT JOIN instead of whereNotExists for better performance
     */
    private function cleanupOrphanedFlights($cutoffDate, $dryRun, $chunkSize = 5000): int
    {
        // Optimized approach: Use LEFT JOIN to find orphaned flights
        // This is much faster than whereNotExists with millions of packages
        $countQuery = DB::table('flight_data as fd')
            ->leftJoin('packages as p', function ($join) {
                $join->on('p.outbound_flight_id', '=', 'fd.id')
                     ->orOn('p.inbound_flight_id', '=', 'fd.id');
            })
            ->where('fd.created_at', '<', $cutoffDate)
            ->whereNull('p.id')
            ->select(DB::raw('COUNT(DISTINCT fd.id) as count'));

        $result = $countQuery->first();
        $count = $result ? (int) $result->count : 0;

        if ($count === 0) {
            $this->line("  No orphaned flight data found.");
            return 0;
        }

        $this->line("  Found {$count} orphaned flight records to delete.");

        if ($dryRun) {
            return $count;
        }

        // Delete in larger batches for better performance
        $deleted = 0;
        $chunkSize = 5000;
        $totalChunks = ceil($count / $chunkSize);
        $currentChunk = 0;

        $this->line("  Processing in chunks of {$chunkSize}...");

        while (true) {
            // Use optimized LEFT JOIN query instead of whereNotExists
            $ids = DB::table('flight_data as fd')
                ->leftJoin('packages as p', function ($join) {
                    $join->on('p.outbound_flight_id', '=', 'fd.id')
                         ->orOn('p.inbound_flight_id', '=', 'fd.id');
                })
                ->where('fd.created_at', '<', $cutoffDate)
                ->whereNull('p.id')
                ->select('fd.id')
                ->distinct()
                ->limit($chunkSize)
                ->pluck('id')
                ->toArray();

            if (empty($ids)) {
                break;
            }

            $chunkDeleted = DB::table('flight_data')->whereIn('id', $ids)->delete();
            $deleted += $chunkDeleted;
            $currentChunk++;

            if ($currentChunk % 10 === 0 || $currentChunk === $totalChunks) {
                $progress = round(($currentChunk / $totalChunks) * 100, 1);
                $this->line("  Progress: {$currentChunk}/{$totalChunks} chunks ({$progress}%) - {$deleted} deleted so far...");
            }
        }

        $this->line("  Deleted {$deleted} flight records.");

        return $deleted;
    }

    /**
     * Clean up orphaned hotel_data (not used by any packages)
     * hotel_offers will be deleted automatically due to cascade
     * Optimized query using LEFT JOIN instead of whereNotExists
     */
    private function cleanupOrphanedHotels($cutoffDate, $dryRun, $chunkSize = 5000): int
    {
        // Optimized approach: Use LEFT JOIN to find orphaned hotels
        $countQuery = DB::table('hotel_data as hd')
            ->leftJoin('packages as p', 'p.hotel_data_id', '=', 'hd.id')
            ->where('hd.created_at', '<', $cutoffDate)
            ->whereNull('p.id')
            ->select(DB::raw('COUNT(DISTINCT hd.id) as count'));

        $result = $countQuery->first();
        $count = $result ? (int) $result->count : 0;

        if ($count === 0) {
            $this->line("  No orphaned hotel data found.");
            return 0;
        }

        $this->line("  Found {$count} orphaned hotel records to delete.");

        if ($dryRun) {
            return $count;
        }

        // Delete in larger batches for better performance
        // hotel_offers will be deleted automatically due to cascade
        $deleted = 0;
        $totalChunks = ceil($count / $chunkSize);
        $currentChunk = 0;

        $this->line("  Processing in chunks of {$chunkSize}...");

        while (true) {
            // Use optimized LEFT JOIN query instead of whereNotExists
            $ids = DB::table('hotel_data as hd')
                ->leftJoin('packages as p', 'p.hotel_data_id', '=', 'hd.id')
                ->where('hd.created_at', '<', $cutoffDate)
                ->whereNull('p.id')
                ->select('hd.id')
                ->distinct()
                ->limit($chunkSize)
                ->pluck('id')
                ->toArray();

            if (empty($ids)) {
                break;
            }

            $chunkDeleted = DB::table('hotel_data')->whereIn('id', $ids)->delete();
            $deleted += $chunkDeleted;
            $currentChunk++;

            if ($currentChunk % 10 === 0 || $currentChunk === $totalChunks) {
                $progress = round(($currentChunk / $totalChunks) * 100, 1);
                $this->line("  Progress: {$currentChunk}/{$totalChunks} chunks ({$progress}%) - {$deleted} deleted so far...");
            }
        }

        $this->line("  Deleted {$deleted} hotel records (and their offers via cascade).");

        return $deleted;
    }

    /**
     * Clean up old client_searches (with soft deletes)
     */
    private function cleanupClientSearches($cutoffDate, $dryRun, $chunkSize = 5000): int
    {
        // Use package_created_at if available, otherwise created_at
        $query = DB::table('client_searches')
            ->where(function ($q) use ($cutoffDate) {
                $q->where('package_created_at', '<', $cutoffDate)
                  ->orWhere(function ($q2) use ($cutoffDate) {
                      $q2->whereNull('package_created_at')
                         ->where('created_at', '<', $cutoffDate);
                  });
            });

        $count = $query->count();

        if ($count === 0) {
            $this->line("  No old client searches found.");
            return 0;
        }

        $this->line("  Found {$count} old client searches to delete.");

        if ($dryRun) {
            return $count;
        }

        // Delete in larger batches for better performance
        $deleted = 0;
        $chunkSize = 5000;
        $totalChunks = ceil($count / $chunkSize);
        $currentChunk = 0;

        $this->line("  Processing in chunks of {$chunkSize}...");

        while (true) {
            $ids = DB::table('client_searches')
                ->where(function ($q) use ($cutoffDate) {
                    $q->where('package_created_at', '<', $cutoffDate)
                      ->orWhere(function ($q2) use ($cutoffDate) {
                          $q2->whereNull('package_created_at')
                             ->where('created_at', '<', $cutoffDate);
                      });
                })
                ->limit($chunkSize)
                ->pluck('id')
                ->toArray();

            if (empty($ids)) {
                break;
            }

            $chunkDeleted = DB::table('client_searches')->whereIn('id', $ids)->delete();
            $deleted += $chunkDeleted;
            $currentChunk++;

            if ($currentChunk % 10 === 0 || $currentChunk === $totalChunks) {
                $progress = round(($currentChunk / $totalChunks) * 100, 1);
                $this->line("  Progress: {$currentChunk}/{$totalChunks} chunks ({$progress}%) - {$deleted} deleted so far...");
            }
        }

        $this->line("  Deleted {$deleted} client search records.");

        return $deleted;
    }
}
