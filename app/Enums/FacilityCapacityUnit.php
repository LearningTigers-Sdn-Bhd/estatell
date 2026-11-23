<?php

namespace App\Enums;

/**
 * What a facility's capacity counts: guests (halls, activities) or pieces (kayaks).
 */
enum FacilityCapacityUnit: string
{
    case Pax = 'pax';
    case Units = 'units';

    public function label(): string
    {
        return match ($this) {
            self::Pax => __('pax'),
            self::Units => __('units'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $unit): array => ['value' => $unit->value, 'label' => $unit->label()],
            self::cases(),
        );
    }
}
