<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdConfigCsv extends Model
{
    protected $guarded = [];

    protected $table = 'ad_config_csv';

    public function adConfig(): BelongsTo
    {
        return $this->belongsTo(AdConfig::class);
    }
}
