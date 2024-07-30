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
}
