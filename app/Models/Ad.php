<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ad extends Model
{
    protected $guarded = [];

    public function outboundFlight(): BelongsTo
    {
        return $this->belongsTo(FlightData::class, 'outbound_flight_id');
    }

    public function inboundFlight(): BelongsTo
    {
        return $this->belongsTo(FlightData::class, 'inbound_flight_id');
    }

    public function hotelData(): BelongsTo
    {
        return $this->belongsTo(HotelData::class, 'hotel_data_id');
    }

    public function adConfig(): BelongsTo
    {
        return $this->belongsTo(AdConfig::class, 'ad_config_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class, 'destination_id');
    }
}
