<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotelReview extends Model
{
    protected $guarded = [];

    protected $casts = [
        'review_date' => 'datetime',
        'average_score' => 'decimal:1',
        'is_anonymous' => 'boolean',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
}
