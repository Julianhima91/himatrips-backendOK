<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotelReviewSummary extends Model
{
    protected $guarded = [];

    protected $casts = [
        'score_breakdown' => 'array',
        'total_score' => 'decimal:1',
        'last_updated' => 'datetime',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
}
