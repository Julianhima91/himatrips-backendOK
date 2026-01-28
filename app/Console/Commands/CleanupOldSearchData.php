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
     * Clean up old flight_data based on date only (much faster)
     * Since we delete packages older than cutoff date, their flight_data will be orphaned anyway
     */
    private function cleanupOrphanedFlights($cutoffDate, $dryRun, $chunkSize = 5000): int
    {
        // Simple approach: Delete all flight_data older than cutoff date
        // This is much faster than checking if orphaned, and safe since we delete old packages first
        $countQuery = DB::table('flight_data')
            ->where('created_at', '<', $cutoffDate);

        $count = $countQuery->count();

        if ($count === 0) {
            $this->line("  No old flight data found.");
            return 0;
        }

        $this->line("  Found {$count} old flight records to delete.");

        if ($dryRun) {
            return $count;
        }

        // Delete in larger batches for better performance
        $deleted = 0;
        $totalChunks = ceil($count / $chunkSize);
        $currentChunk = 0;

        $this->line("  Processing in chunks of {$chunkSize}...");

        while (true) {
            $ids = DB::table('flight_data')
                ->where('created_at', '<', $cutoffDate)
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
     * Clean up old hotel_data based on date only (much faster)
     * hotel_offers will be deleted automatically due to cascade
     * Since we delete packages older than cutoff date, their hotel_data will be orphaned anyway
     */
    private function cleanupOrphanedHotels($cutoffDate, $dryRun, $chunkSize = 5000): int
    {
        // Simple approach: Delete all hotel_data older than cutoff date
        // This is much faster than checking if orphaned, and safe since we delete old packages first
        $countQuery = DB::table('hotel_data')
            ->where('created_at', '<', $cutoffDate);

        $count = $countQuery->count();

        if ($count === 0) {
            $this->line("  No old hotel data found.");
            return 0;
        }

        $this->line("  Found {$count} old hotel records to delete.");

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
            $ids = DB::table('hotel_data')
                ->where('created_at', '<', $cutoffDate)
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
