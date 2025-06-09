<?php

namespace App\Jobs;

use App\Models\Airport;
use App\Models\Destination;
use App\Models\DestinationOrigin;
use App\Models\Origin;
use App\Models\PackageConfig;
use App\Models\Scopes\ActiveScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DestinationPackageConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $destinationId;

    public function __construct($destinationId)
    {
        $this->destinationId = $destinationId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $destination = Destination::find($this->destinationId);
        $destinationAirport = $destination->airports()->first();

        $origins = Origin::query()->get();

        Log::info("Creating connections for $destination->name ...");

        foreach ($origins as $origin) {
            $exists = DestinationOrigin::where('origin_id', $origin->id)
                ->where('destination_id', $destination->id)
                ->exists();

            $originAirport = Airport::query()->where('origin_id', $origin->id)->first();

            //            Log::warning("Exists: $exists");
            //            Log::warning("O Airport: $originAirport");
            //            Log::warning("D Airport: $destinationAirport");
            if (! $exists && $origin->name !== $destination->name && $originAirport && $destinationAirport) {
                $destinationOrigin = DestinationOrigin::create([
                    'origin_id' => $origin->id,
                    'destination_id' => $destination->id,
                ]);

                Log::info("Added connection with id $destinationOrigin->id");
                Log::info("Origin ID $origin->id");
                Log::info("Destination ID $destination->id");
                Log::info('===================================');

                $hasPackageConfig = PackageConfig::query()
                    ->withoutGlobalScope(ActiveScope::class)
                    ->where('destination_origin_id', $destinationOrigin->id)->first();

                if (! $hasPackageConfig) {
                    $commissionRule = $destination->commissionRule;

                    $commissionAmount = 80;
                    $commissionPercentage = 10;

                    if ($commissionRule) {
                        Log::info("Commission Rule: $commissionRule->name");

                        $commissionAmount = $commissionRule->minimum_number;
                        $commissionPercentage = $commissionRule->minimum_percentage;
                    }

                    $packageConfig = PackageConfig::create([
                        'destination_origin_id' => $destinationOrigin->id,
                        'commission_amount' => $commissionAmount,
                        'commission_percentage' => $commissionPercentage,
                    ]);

                    Log::info("Created package config with id $packageConfig->id");
                }
            }
        }
    }
}
