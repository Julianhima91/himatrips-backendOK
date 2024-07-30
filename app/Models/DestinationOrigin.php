<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class DestinationOrigin extends Model
{
    protected $guarded = [];

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
}
