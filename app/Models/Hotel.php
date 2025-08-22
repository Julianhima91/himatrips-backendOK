<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    public function facilities(): HasMany
    {
        return $this->hasMany(HotelFacility::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(HotelReview::class);
    }

    public function reviewSummary(): HasOne
    {
        return $this->hasOne(HotelReviewSummary::class);
    }
}
