<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FailedAvailabilityCheck extends Model
{
    protected $table = 'failed_availability_checks';

    protected $guarded = [];

    protected $casts = [
        'is_return_flight' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function originAirport()
    {
        return $this->belongsTo(Airport::class, 'origin_airport_id');
    }

    public function destinationAirport()
    {
        return $this->belongsTo(Airport::class, 'destination_airport_id');
    }

    public function destinationOrigin()
    {
        return $this->belongsTo(DestinationOrigin::class, 'destination_origin_id');
    }
}

