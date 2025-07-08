<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Hotel extends Model
{
    protected $guarded = [];

    public function destinations(): BelongsToMany
    {
        return $this->belongsToMany(Destination::class, 'destination_hotel');
    }

    public function hotelPhotos(): HasMany
    {
        return $this->hasMany(HotelPhoto::class);
    }

    public function transfers(): MorphToMany
    {
        return $this->morphedByMany(Transfer::class, 'bundleable');
    }

    public function roomTypes(): HasMany
    {
        return $this->hasMany(RoomType::class);
    }

    public function hotelData(): HasMany
    {
        return $this->hasMany(HotelData::class, 'hotel_id', 'id');
    }
}
