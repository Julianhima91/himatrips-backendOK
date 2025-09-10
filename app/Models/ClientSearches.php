<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientSearches extends Package
{
    use SoftDeletes;

    protected $table = 'client_searches';

    public function inboundFlight(): BelongsTo
    {
        return $this->belongsTo(FlightData::class, 'inbound_flight_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'package_id');
    }

    public function packageConfig(): BelongsTo
    {
        return $this->belongsTo(PackageConfig::class, 'package_config_id');
    }
}
