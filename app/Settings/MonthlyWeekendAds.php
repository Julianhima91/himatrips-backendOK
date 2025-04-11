<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class MonthlyWeekendAds extends Settings
{
    public int $monthly;

    public static function group(): string
    {
        return 'general';
    }
}
