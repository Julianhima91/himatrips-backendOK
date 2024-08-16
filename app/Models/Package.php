<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Package extends Model
{
    protected $guarded = [];

    public function transfers(): MorphToMany
    {
        return $this->morphedByMany(Transfer::class, 'packageable');
    }

    public function outboundFlight(): BelongsTo
    {
        return $this->belongsTo(FlightData::class, 'outbound_flight_id');
    }

    public function inboundFlight(): BelongsTo
    {
        return $this->belongsTo(FlightData::class, 'inbound_flight_id');
    }

    public function hotelData(): BelongsTo
    {
        return $this->belongsTo(HotelData::class, 'hotel_data_id');
    }

    public function packageConfig(): BelongsTo
    {
        return $this->belongsTo(PackageConfig::class, 'package_config_id');
    }

    public function getMaxTotalPriceAttribute()
    {
        return Package::whereIn('id', $this->pluck('id')->toArray())->max('total_price');
    }
}
