<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/** The first date an inclusion is served, counted from a unit's arrival. */
enum InclusionStart: string
{
    case ArrivalDay = 'arrival_day';
    case DayAfterArrival = 'day_after_arrival';

    public function label(): string
    {
        return match ($this) {
            self::ArrivalDay => __('Arrival day'),
            self::DayAfterArrival => __('Day after arrival'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $start): array => ['value' => $start->value, 'label' => $start->label()],
            self::cases(),
        );
    }

    public function firstDate(CarbonImmutable $arrival): CarbonImmutable
    {
        return match ($this) {
            self::ArrivalDay => $arrival->startOfDay(),
            self::DayAfterArrival => $arrival->startOfDay()->addDay(),
        };
    }
}
