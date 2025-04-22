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

class OriginPackageConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $originId;

    /**
     * Create a new job instance.
     */
    public function __construct($originId)
    {
        $this->originId = $originId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $origin = Origin::find($this->originId);

        $destinations = Destination::query()->where('is_active', true)->get();

        Log::info("Creating connections for $origin->name ...");

        foreach ($destinations as $destination) {
            $exists = DestinationOrigin::where('origin_id', $origin->id)
                ->where('destination_id', $destination->id)
                ->exists();

            $originAirport = Airport::query()->where('origin_id', $origin->id)->first();
            $destinationAirport = $destination->airports()->first();

            if (! $exists && $origin->name !== $destination->name && $originAirport && $destinationAirport) {
                $destinationOrigin = DestinationOrigin::create([
                    'origin_id' => $origin->id,
                    'destination_id' => $destination->id,
                ]);

                Log::info("Added connection with id $destinationOrigin->id");

                $hasPackageConfig = PackageConfig::query()
                    ->withoutGlobalScope(ActiveScope::class)
                    ->where('destination_origin_id', $destinationOrigin->id)->first();

                if (! $hasPackageConfig) {
                    $packageConfig = PackageConfig::create([
                        'destination_origin_id' => $destinationOrigin->id,
                        'commission_amount' => 80,
                        'commission_percentage' => 10,
                    ]);

                    Log::info("Created package config with id $packageConfig->id");
                }
            }
        }
    }
}
