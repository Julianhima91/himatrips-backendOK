<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Origin extends Model
{
    protected $guarded = [];

    public function airports(): HasMany
    {
        return $this->hasMany(Airport::class);
    }

    public function destinations(): BelongsToMany
    {
        return $this->belongsToMany(Destination::class, 'destination_origins')->using(DestinationOrigin::class)->withPivot('live_search');
    }

    public function destinationOrigin(): HasMany
    {
        return $this->hasMany(DestinationOrigin::class);
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }
}
