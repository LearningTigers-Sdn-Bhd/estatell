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

    /** The choice card title on the facility form, for example "People". */
    public function title(): string
    {
        return match ($this) {
            self::Pax => __('People'),
            self::Units => __('Items'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Pax => __('Count guests. e.g. hall, onsen, night walk.'),
            self::Units => __('Count things. e.g. kayaks, BBQ pits.'),
        };
    }

    /** @return list<array{value: string, label: string, title: string, description: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $unit): array => ['value' => $unit->value, 'label' => $unit->label(), 'title' => $unit->title(), 'description' => $unit->description()],
            self::cases(),
        );
    }
}
