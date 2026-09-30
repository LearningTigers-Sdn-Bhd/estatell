<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/** The last date an inclusion is served, counted from a unit's departure. */
enum InclusionEnd: string
{
    case DepartureDay = 'departure_day';
    case NightBeforeDeparture = 'night_before_departure';

    public function label(): string
    {
        return match ($this) {
            self::DepartureDay => __('Departure day'),
            self::NightBeforeDeparture => __('Night before departure'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $end): array => ['value' => $end->value, 'label' => $end->label()],
            self::cases(),
        );
    }

    public function lastDate(CarbonImmutable $departure): CarbonImmutable
    {
        return match ($this) {
            self::DepartureDay => $departure->startOfDay(),
            self::NightBeforeDeparture => $departure->startOfDay()->subDay(),
        };
    }
}
