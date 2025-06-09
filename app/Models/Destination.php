<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Tags\HasTags;
use Staudenmeir\EloquentHasManyDeep\HasManyDeep;
use Staudenmeir\EloquentHasManyDeep\HasRelationships;

class Destination extends Model
{
    use HasRelationships, HasTags;

    protected $guarded = [];

    protected $casts = [
        'board_options' => 'array',
        'active_months' => 'array',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function adConfigs(): BelongsToMany
    {
        return $this->belongsToMany(AdConfig::class);
    }

    public function hotels(): BelongsToMany
    {
        return $this->belongsToMany(Hotel::class, 'destination_hotel');
    }

    public function airports(): BelongsToMany
    {
        return $this->belongsToMany(Airport::class);
    }

    public function origins(): BelongsToMany
    {
        return $this->belongsToMany(Origin::class, 'destination_origins')->using(DestinationOrigin::class)->withPivot('live_search');
    }

    public function destinationOrigin(): HasMany
    {
        return $this->hasMany(DestinationOrigin::class);
    }

    public function packages(): HasManyDeep
    {
        return $this->hasManyDeepFromRelations($this->destinationOrigin(), (new DestinationOrigin)->packageConfigs(), (new PackageConfig)->packages());
    }

    public function hotelData(): HasManyDeep
    {
        return $this->hasManyDeepFromRelations($this->destinationOrigin(), (new DestinationOrigin)->packageConfigs(), (new PackageConfig)->packages(), (new Package)->hotelData());

    }

    public function destinationPhotos(): HasMany
    {
        return $this->hasMany(DestinationPhoto::class);
    }

    public function getOfferCategoryAttribute($value)
    {
        return json_decode($value, true);
    }

    public function setOfferCategoryAttribute($value): void
    {
        $this->attributes['offer_category'] = json_encode($value);
    }

    public function commissionRule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }
}
