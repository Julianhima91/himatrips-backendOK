<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotelOffer extends Model
{
    protected $guarded = [];

    public function hotelData(): BelongsTo
    {
        return $this->belongsTo(HotelData::class);
    }
}
