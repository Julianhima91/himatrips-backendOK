<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionRule extends Model
{
    protected $guarded = [];

    public function destinations(): HasMany
    {
        return $this->hasMany(Destination::class, 'commission_rule_id');
    }
}
