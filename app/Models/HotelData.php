<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HotelData extends Model
{
    protected $guarded = [];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class, 'hotel_id', 'id');
    }

    public function package(): HasOne
    {
        return $this->hasOne(Package::class, 'hotel_data_id', 'id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(HotelOffer::class);
    }

    public function cheapestOffer()
    {
        return $this->hasMany(HotelOffer::class)->orderBy('price')->limit(1);
    }

    public function getCheapestOfferPriceAttribute()
    {
        return $this->cheapestOffer ? $this->cheapestOffer->first()->price : null;
    }
}
