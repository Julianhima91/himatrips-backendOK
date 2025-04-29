<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Tags\HasTags;
use Staudenmeir\EloquentHasManyDeep\HasRelationships;

class DestinationOriginPhoto extends Model
{
    use HasRelationships, HasTags;

    protected $guarded = [];

    public function destinationOrigin(): BelongsTo
    {
        return $this->belongsTo(DestinationOrigin::class);
    }
}
