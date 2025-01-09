<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AdConfig extends Model
{
    protected $guarded = [];

    public function origin(): BelongsTo
    {
        return $this->belongsTo(Origin::class);
    }

    public function airports(): BelongsToMany
    {
        return $this->belongsToMany(Airport::class, 'ad_config_airports');
    }

    public function destinations(): BelongsToMany
    {
        return $this->belongsToMany(Destination::class, 'ad_config_destinations');
    }

    public function getExtraOptionsAttribute($value)
    {
        return json_decode($value, true);
    }

    public function setExtraOptionsAttribute($value)
    {
        $this->attributes['extra_options'] = json_encode($value);
    }
}
