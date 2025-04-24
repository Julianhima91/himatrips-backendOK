<?php

namespace App\Enums;

enum BoardOptionEnum: string
{
    case BB = 'Bed & Breakfast';
    case HB = 'Half Board';
    case FB = 'Full Board';
    case AI = 'All Inclusive';
    case RO = 'Room Only';
    case CB = 'Continental Breakfast';
    case BD = 'Bed & Dinner';

    public function getLabel(): string
    {
        return $this->value;
    }

    public static function fromName(string $name): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        return null;
    }
}
