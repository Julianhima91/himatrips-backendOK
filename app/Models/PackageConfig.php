<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackageConfig extends Model
{
    protected $guarded = [];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'origin_airports' => 'array',
        'destination_airports' => 'array',
        'airlines' => 'array',
        'number_of_nights' => 'array',
        'last_processed_at' => 'datetime',
        'direct_flight' => 'boolean',
    ];

    public function destination_origin(): BelongsTo
    {
        return $this->belongsTo(DestinationOrigin::class, 'destination_origin_id');
    }

    public function flightData(): HasMany
    {
        return $this->hasMany(FlightData::class, 'package_config_id');
    }

    public function hotelData(): HasMany
    {
        return $this->hasMany(HotelData::class, 'package_config_id');
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class, 'package_config_id');
    }

    public function directFlightsAvailabilityDates()
    {
        return $this->destination_origin->directFlightsAvailability();
    }
}
