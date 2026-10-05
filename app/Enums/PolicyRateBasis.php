<?php

namespace App\Enums;

/**
 * Which rate a percentage policy takes its percent of.
 */
enum PolicyRateBasis: string
{
    case BookedRate = 'booked_rate';
    case CurrentRate = 'current_rate';

    public function label(): string
    {
        return match ($this) {
            self::BookedRate => __('Booked rate'),
            self::CurrentRate => __('Current rate'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
