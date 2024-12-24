<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends Model
{
    protected $guarded = [];

    public function origin(): BelongsTo
    {
        return $this->belongsTo(Origin::class);
    }
}
