<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Staudenmeir\EloquentHasManyDeep\HasManyDeep;
use Staudenmeir\EloquentHasManyDeep\HasRelationships;

class Destination extends Model
{
    use HasRelationships;

    protected $guarded = [];

    public function hotels(): BelongsToMany
    {
        return $this->belongsToMany(Hotel::class, 'destination_hotel');
    }

    public function airports(): BelongsToMany
    {
        return $this->belongsToMany(Airport::class);
    }

    public function origins(): BelongsToMany
    {
        return $this->belongsToMany(Origin::class, 'destination_origins')->using(DestinationOrigin::class);
    }

    public function destinationOrigin(): HasMany
    {
        return $this->hasMany(DestinationOrigin::class);
    }

    public function packages(): HasManyDeep
    {
        return $this->hasManyDeepFromRelations($this->destinationOrigin(), (new DestinationOrigin)->packageConfigs(), (new PackageConfig)->packages());
    }

    public function hotelData(): HasManyDeep
    {
        return $this->hasManyDeepFromRelations($this->destinationOrigin(), (new DestinationOrigin)->packageConfigs(), (new PackageConfig)->packages(), (new Package)->hotelData());

    }

    public function destinationPhotos(): HasMany
    {
        return $this->hasMany(DestinationPhoto::class);
    }
}
