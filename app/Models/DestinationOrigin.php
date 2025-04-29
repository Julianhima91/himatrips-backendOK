<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Spatie\Tags\HasTags;

class DestinationOrigin extends Pivot
{
    use HasTags;

    protected $guarded = [];

    protected $casts = [
        'boarding_options' => 'array',
    ];

    protected $table = 'destination_origins';

    public $incrementing = true;

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function origin(): BelongsTo
    {
        return $this->belongsTo(Origin::class);
    }

    public function packageConfigs(): HasMany
    {
        return $this->hasMany(PackageConfig::class, 'destination_origin_id');
    }

    public function packages(): HasManyThrough
    {
        return $this->hasManyThrough(Package::class, PackageConfig::class, 'destination_origin_id', 'package_config_id');
    }

    public function directFlightsAvailability()
    {
        return $this->hasMany(DirectFlightAvailability::class, 'destination_origin_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(DestinationOriginPhoto::class, 'destination_origin_id', 'id');
    }
}
