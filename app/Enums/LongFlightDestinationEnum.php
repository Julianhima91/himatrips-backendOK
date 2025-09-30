<?php

namespace App\Enums;

enum LongFlightDestinationEnum: int
{
    case MALDIVES = 13;
    case ZANZIBAR = 57;
    case BALI = 59;
    case PHUKET = 50;

    /**
     * Get all destination IDs that require long flights
     */
    public static function ids(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the destination name for display purposes
     */
    public function getName(): string
    {
        return match ($this) {
            self::MALDIVES => 'Maldives',
            self::ZANZIBAR => 'Zanzibar',
            self::BALI => 'Bali',
            self::PHUKET => 'Phuket',
        };
    }

    /**
     * Check if a destination ID is a long flight destination
     */
    public static function isLongFlightDestination(int $destinationId): bool
    {
        return in_array($destinationId, self::ids());
    }

    /**
     * Get enum case by destination ID
     */
    public static function fromId(int $destinationId): \Closure
    {
        return collect(self::cases())->first(fn ($case) => $case->value === $destinationId);
    }
}
