<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function ($country) {
            if ($country->code) {
                $country->code = strtoupper($country->code);
            }
        });
    }

    public function destinations(): HasMany
    {
        return $this->hasMany(Destination::class);
    }

    public function origins(): HasMany
    {
        return $this->hasMany(Origin::class);
    }

    public function airports(): HasMany
    {
        return $this->hasMany(Airport::class);
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }
}
