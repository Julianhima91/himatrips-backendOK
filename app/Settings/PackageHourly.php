<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class PackageHourly extends Settings
{
    public int $hourly;

    public static function group(): string
    {
        return 'general';
    }
}
