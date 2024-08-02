<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightData extends Model
{
    protected $guarded = [];

    protected $casts = [
        'departure' => 'datetime',
        'arrival' => 'datetime',
    ];

    public function originAirport(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'origin', 'codeIataAirport');
    }

    public function destinationAirport(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'destination', 'codeIataAirport');
    }
}
