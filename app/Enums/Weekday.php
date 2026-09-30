<?php

namespace App\Enums;

use Carbon\CarbonInterface;
use LogicException;

enum Weekday: string
{
    case Monday = 'monday';
    case Tuesday = 'tuesday';
    case Wednesday = 'wednesday';
    case Thursday = 'thursday';
    case Friday = 'friday';
    case Saturday = 'saturday';
    case Sunday = 'sunday';

    public function label(): string
    {
        return match ($this) {
            self::Monday => __('Monday'),
            self::Tuesday => __('Tuesday'),
            self::Wednesday => __('Wednesday'),
            self::Thursday => __('Thursday'),
            self::Friday => __('Friday'),
            self::Saturday => __('Saturday'),
            self::Sunday => __('Sunday'),
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Monday => __('Mon'),
            self::Tuesday => __('Tue'),
            self::Wednesday => __('Wed'),
            self::Thursday => __('Thu'),
            self::Friday => __('Fri'),
            self::Saturday => __('Sat'),
            self::Sunday => __('Sun'),
        };
    }

    /** The weekday of a date. */
    public static function forDate(CarbonInterface $date): self
    {
        foreach (self::cases() as $case) {
            if ($case->isoNumber() === $date->dayOfWeekIso) {
                return $case;
            }
        }

        throw new LogicException('A date always has an ISO weekday from 1 to 7.');
    }

    /** The ISO weekday number, 1 = Monday to 7 = Sunday. */
    public function isoNumber(): int
    {
        return match ($this) {
            self::Monday => 1,
            self::Tuesday => 2,
            self::Wednesday => 3,
            self::Thursday => 4,
            self::Friday => 5,
            self::Saturday => 6,
            self::Sunday => 7,
        };
    }

    /**
     * @return list<array{value: string, label: string, shortLabel: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $day): array => ['value' => $day->value, 'label' => $day->label(), 'shortLabel' => $day->shortLabel()],
            self::cases(),
        );
    }
}
