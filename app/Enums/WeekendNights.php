<?php

namespace App\Enums;

enum WeekendNights: string
{
    case FriSat = 'fri_sat';
    case SatSun = 'sat_sun';

    public function label(): string
    {
        return match ($this) {
            self::FriSat => __('Friday + Saturday'),
            self::SatSun => __('Saturday + Sunday'),
        };
    }

    /**
     * The ISO weekdays (1 = Monday, 7 = Sunday) whose nights are weekend nights.
     *
     * @return list<int>
     */
    public function isoWeekdays(): array
    {
        return match ($this) {
            self::FriSat => [5, 6],
            self::SatSun => [6, 7],
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
