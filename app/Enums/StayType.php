<?php

namespace App\Enums;

/**
 * How a reservation unit is sold: for nights, or for a number of hours on one day.
 * See docs/12-reservations/10-day-use.md.
 */
enum StayType: string
{
    case Overnight = 'overnight';
    case DayUse = 'day_use';

    public function label(): string
    {
        return match ($this) {
            self::Overnight => __('Overnight'),
            self::DayUse => __('Day use'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
