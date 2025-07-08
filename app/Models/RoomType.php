<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomType extends Model
{
    protected $guarded = [];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function hotelData(): BelongsTo
    {
        return $this->belongsTo(HotelData::class);
    }

    public function hotelOffer(): BelongsTo
    {
        return $this->belongsTo(HotelOffer::class);
    }
}
