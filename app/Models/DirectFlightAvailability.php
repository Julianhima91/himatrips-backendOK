<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DirectFlightAvailability extends Model
{
    protected $guarded = [];

    public function destinationOrigin()
    {
        return $this->belongsTo(DestinationOrigin::class);
    }
}
