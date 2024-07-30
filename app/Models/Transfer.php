<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Transfer extends Model
{
    protected $guarded = [];

    public function packages(): MorphToMany
    {
        return $this->morphedByMany(Package::class, 'packageable');
    }
}
