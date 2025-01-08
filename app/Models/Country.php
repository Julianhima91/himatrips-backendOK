<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    protected $guarded = [];

    public function destinations(): HasMany
    {
        return $this->hasMany(Destination::class);
    }

    public function origins(): HasMany
    {
        return $this->hasMany(Origin::class);
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }
}
