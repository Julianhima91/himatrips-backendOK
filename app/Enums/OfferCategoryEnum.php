<?php

namespace App\Enums;

enum OfferCategoryEnum: string
{
    case HOLIDAY = 'holiday';
    case ECONOMIC = 'economic';
    case WEEKEND = 'weekend';

    public function getLabel(): ?string
    {
        return $this->name;
    }
}
