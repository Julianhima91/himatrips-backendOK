<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class MaxTransitTime extends Settings
{
    public int $minutes;

    public static function group(): string
    {
        return 'general';
    }
}
